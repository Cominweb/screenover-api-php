<?php

namespace Screenover\Api;

use Screenover\Api\Exception\ApiException;
use Screenover\Api\Exception\AuthException;
use Screenover\Api\Exception\NotFoundException;
use Screenover\Api\Http\Client;
use Screenover\Api\Media\Uploader;
use Screenover\Api\Query\OptionParser;

/**
 * PHP SDK for the ScreenOver API.
 *
 * Drop-in replacement for the legacy Mediative PHP wrapper (MediativeApi). The public
 * interface (auth/get/post/put/delete + token & secure helpers) is kept identical so
 * existing client code only needs new credentials, a new domain and the project id.
 *
 * Internally it speaks the PayloadCMS REST API:
 *   - header-based authentication (API key or JWT) instead of query-string tokens
 *   - PATCH for updates (exposed as put())
 *   - { docs } / { doc } responses normalised back to the Mediative-like shape
 *   - project scoping instead of the Mediative "domain"
 *
 * @example
 *   $client = new ScreenoverApi(PUBLIC, SECRET, DOMAIN);
 *   $client->auth();
 *   $client->setProject($projectId);
 *   $client->get('media');
 */
class ScreenoverApi
{
    /** Authenticate with a PayloadCMS API key (no login round-trip, recommended for SDK usage). */
    public const AUTH_API_KEY = 'apikey';

    /** Authenticate with an email/password login that returns a JWT. */
    public const AUTH_LOGIN = 'login';

    /** Default ScreenOver domain (clients are all hosted on screenover.com). */
    public const DEFAULT_DOMAIN = 'screenover.com';

    /**
     * Collections that require a "project" field on creation (multi-tenancy scoping).
     *
     * @var string[]
     */
    private const PROJECT_SCOPED = [
        'media',
        'category',
        'category-media',
        'tags',
        'styles',
        'media-watch',
        'media-watch-result',
    ];

    /** @var string Public credential: an identifier (API key mode) or the email (login mode). */
    protected string $public;

    /** @var string Secret credential: the API key (API key mode) or the password (login mode). */
    protected string $secret;

    /** @var string The ScreenOver domain (no protocol, no path), e.g. "demo.screenover.tv". */
    protected string $domain;

    /** @var string|null The active session token (API key value or JWT). */
    protected ?string $token = null;

    /** @var string|null The active project id used to scope created resources. */
    protected ?string $project = null;

    protected string $authMode = self::AUTH_API_KEY;

    protected bool $secure = true;

    protected Client $http;

    protected OptionParser $optionParser;

    /** @var array<string,mixed>|null */
    protected ?array $lastPagination = null;

    public function __construct(string $public, string $secret, string $domain = self::DEFAULT_DOMAIN)
    {
        $this->setPublic($public);
        $this->setSecret($secret);
        $this->setDomain($domain);

        $this->http = new Client();
        $this->optionParser = new OptionParser();
    }

    // ---------------------------------------------------------------------
    // Credentials & configuration
    // ---------------------------------------------------------------------

    public function setPublic(string $public): self
    {
        if ($public === '') {
            throw new AuthException('Please provide your public auth token.');
        }
        $this->public = $public;
        return $this;
    }

    public function getPublic(): string
    {
        return $this->public;
    }

    public function setSecret(string $secret): self
    {
        if ($secret === '') {
            throw new AuthException('Please provide your secret auth token.');
        }
        $this->secret = $secret;
        return $this;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setDomain(string $domain): self
    {
        if ($domain === '') {
            throw new AuthException('Please provide the domain on which you would work.');
        }
        // Accept a normal domain (foo.screenover.com) or a local host:port for testing.
        $isDomain = (bool) preg_match('#^([a-zA-Z0-9_-]+\.)*[a-zA-Z0-9\-_]+\.[a-zA-Z]{2,10}$#', $domain);
        $isLocal = (bool) preg_match('#^localhost(:\d+)?$#', $domain)
            || (bool) preg_match('#^127\.0\.0\.1(:\d+)?$#', $domain);
        if (!$isDomain && !$isLocal) {
            throw new AuthException('Please provide the domain without path and protocol.');
        }
        $this->domain = $domain;
        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * Choose the authentication strategy: self::AUTH_API_KEY (default) or self::AUTH_LOGIN.
     */
    public function setAuthMode(string $mode): self
    {
        if ($mode !== self::AUTH_API_KEY && $mode !== self::AUTH_LOGIN) {
            throw new AuthException('Unknown auth mode: ' . $mode);
        }
        $this->authMode = $mode;
        return $this;
    }

    public function getAuthMode(): string
    {
        return $this->authMode;
    }

    public function setToken(string $token): self
    {
        if ($token === '') {
            throw new AuthException('Please provide the token given by the API.');
        }
        $this->token = $token;
        return $this;
    }

    public function getToken(): string
    {
        if ($this->token === null || $this->token === '') {
            throw new AuthException('You should set your auth token before making a request.');
        }
        return $this->token;
    }

    public function enableSecure(): self
    {
        $this->secure = true;
        $this->http->enableSecure();
        return $this;
    }

    public function disableSecure(): self
    {
        $this->secure = false;
        $this->http->disableSecure();
        return $this;
    }

    /**
     * Set the active project used to scope created resources (replaces the Mediative "domain").
     */
    public function setProject(string $projectId): self
    {
        $this->project = $projectId;
        $this->http->setHeader('x-project-id', $projectId);
        return $this;
    }

    /**
     * Alias of setProject(): set the current/active project by id.
     */
    public function setCurrentProject(string $projectId): self
    {
        return $this->setProject($projectId);
    }

    /**
     * Convenience: fetch the accessible projects and select one.
     *
     * - with no argument, auto-selects the project when the account has exactly one
     *   (typical for a migrated client) and throws otherwise so you make an explicit choice;
     * - with a title, selects the first project whose title matches (case-insensitive).
     *
     * Returns the selected project array (including its "id").
     *
     * @return array<string,mixed>
     */
    public function selectProject(?string $title = null): array
    {
        $projects = $this->getProjects();
        if (!is_array($projects) || count($projects) === 0) {
            throw new AuthException('No project is accessible with these credentials.');
        }

        if ($title !== null) {
            foreach ($projects as $project) {
                if (isset($project['title']) && strcasecmp((string) $project['title'], $title) === 0) {
                    $this->setProject((string) $project['id']);
                    return $project;
                }
            }
            throw new NotFoundException('No project found with title "' . $title . '".');
        }

        if (count($projects) > 1) {
            throw new ApiException(
                'Several projects are available; pass a title to selectProject() '
                . 'or call setCurrentProject($id) explicitly.'
            );
        }

        $project = $projects[0];
        $this->setProject((string) $project['id']);
        return $project;
    }

    public function getProject(): ?string
    {
        return $this->project;
    }

    public function getHttpClient(): Client
    {
        return $this->http;
    }

    /**
     * Return pagination metadata from the last list response ({ docs } envelope).
     *
     * @return array<string,mixed>|null
     */
    public function getPagination(): ?array
    {
        return $this->lastPagination;
    }

    public function getTotalDocs(): ?int
    {
        if (!isset($this->lastPagination['totalDocs'])) {
            return null;
        }

        return (int) $this->lastPagination['totalDocs'];
    }

    public function getTotalPages(): ?int
    {
        if (!isset($this->lastPagination['totalPages'])) {
            return null;
        }

        return (int) $this->lastPagination['totalPages'];
    }

    // ---------------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------------

    /**
     * Authenticate against the API and install the auth token/header.
     *
     * In API key mode this is a local operation (no network round-trip): the key is
     * sent on every request through the Authorization header. In login mode it performs
     * a POST /api/users/login and stores the returned JWT.
     *
     * @throws AuthException when login fails
     */
    public function auth(): self
    {
        if ($this->authMode === self::AUTH_API_KEY) {
            $this->token = $this->secret;
            $this->http->setHeader('Authorization', 'users API-Key ' . $this->secret);
            return $this;
        }

        $response = $this->http->request('POST', $this->baseUrl() . '/users/login', [
            'email' => $this->public,
            'password' => $this->secret,
        ]);

        if (empty($response['token']) || !is_string($response['token'])) {
            throw new AuthException('Invalid developer login');
        }

        $this->setToken($response['token']);
        $this->http->setHeader('Authorization', 'JWT ' . $response['token']);
        return $this;
    }

    /**
     * Invalidate the current login session (login mode only).
     */
    public function logout(): self
    {
        if ($this->authMode === self::AUTH_LOGIN && $this->token !== null) {
            $this->http->request('POST', $this->baseUrl() . '/users/logout');
        }
        $this->token = null;
        $this->http->removeHeader('Authorization');
        return $this;
    }

    // ---------------------------------------------------------------------
    // CRUD
    // ---------------------------------------------------------------------

    /**
     * GET a resource.
     *
     *   get('media')                       -> list (scoped to the active project)
     *   get('media', $id)                  -> single document (never scoped)
     *   get('media', ['id' => $id])        -> single document (never scoped)
     *   get('media', [...options])         -> filtered list (where/order/fields/limit...)
     *   get('media', [], true, true, true) -> unscoped list (multi-project opt-out)
     *
     * @param string                         $resource    Collection slug (optionally with /id appended).
     * @param array<string,mixed>|string|int $options
     * @param bool                           $autoMap     Auto-extract the id from $options.
     * @param bool                           $shortCut    Normalise the { doc/docs } envelope.
     * @param bool                           $allProjects Opt-out: do NOT filter by the active project.
     * @return array<string,mixed>|array<int,mixed>
     */
    public function get(
        string $resource,
        $options = [],
        bool $autoMap = true,
        bool $shortCut = true,
        bool $allProjects = false
    ) {
        $resource = $this->extractId($resource, $options, $autoMap);

        // An id in the path means a single-document read: no project scoping.
        $isSingleDocument = (bool) preg_match('#^/?\w[\w-]*/.+$#', $resource);
        if (is_array($options) && !$isSingleDocument) {
            $options = $this->injectProjectFilter($resource, $options, $allProjects);
        }

        $queryString = is_array($options) ? $this->optionParser->build($options) : '';
        $response = $this->http->request('GET', $this->url($resource, $queryString));

        return $this->normalize($response, $shortCut);
    }

    /**
     * POST (create) a resource.
     *
     * @param array<string,mixed> $datas
     * @param array<string,mixed> $options
     * @return array<string,mixed>|array<int,mixed>
     */
    public function post(string $resource, array $datas = [], array $options = [], bool $shortCut = true)
    {
        $datas = $this->injectProject($resource, $datas);
        $queryString = $this->optionParser->build($options);
        $response = $this->http->request('POST', $this->url($resource, $queryString), $datas);

        return $this->normalize($response, $shortCut);
    }

    /**
     * PUT (update) a resource. Emitted as a PATCH request against PayloadCMS.
     *
     *   put('media', ['id' => $id, 'title' => '...'])
     *   put('media/' . $id, ['title' => '...'])
     *
     * @param array<string,mixed> $datas
     * @param array<string,mixed> $options
     * @return array<string,mixed>|array<int,mixed>
     */
    public function put(
        string $resource,
        array $datas = [],
        array $options = [],
        bool $check = true,
        bool $autoMap = true,
        bool $shortCut = true
    ) {
        $hasInlineId = (bool) preg_match('#^\w[\w-]*/.+$#', $resource);

        if ($check && !$hasInlineId && !isset($datas['id'])) {
            throw new ApiException('Please provide an ID to update', 400);
        }

        if ($autoMap && !$hasInlineId && isset($datas['id'])) {
            $resource .= '/' . $datas['id'];
            unset($datas['id']);
        }

        $queryString = $this->optionParser->build($options);
        $response = $this->http->request('PATCH', $this->url($resource, $queryString), $datas);

        return $this->normalize($response, $shortCut);
    }

    /**
     * DELETE a resource.
     *
     *   delete('media', $id)
     *   delete('media', ['id' => $id])
     *   delete('media/' . $id)
     *
     * @param array<string,mixed>|string|int $options
     * @return array<string,mixed>|array<int,mixed>
     */
    public function delete(string $resource, $options = [], bool $autoMap = true, bool $shortCut = true)
    {
        $resource = $this->extractId($resource, $options, $autoMap);
        $response = $this->http->request('DELETE', $this->url($resource));

        return $this->normalize($response, $shortCut);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Call an arbitrary custom endpoint (e.g. media/get-upload-url, storage, reindex...).
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    public function call(string $method, string $path, ?array $body = null): array
    {
        return $this->http->request($method, $this->url($path), $body);
    }

    /**
     * List the projects accessible to the authenticated principal.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>|array<int,mixed>
     */
    public function getProjects(array $options = [])
    {
        return $this->get('projects', $options);
    }

    /**
     * List only categories visible to the public.
     *
     * The helper enforces the visibility filter internally so callers do not need
     * to repeat PayloadCMS-specific query details.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>|array<int,mixed>
     */
    public function getPublicCategories(array $options = [])
    {
        return $this->get('category', $this->mergePublicCategoryFilter($options));
    }

    /**
     * Upload a local file as a new media (handles the full GCS multi-step flow).
     *
     * @param array<string,mixed> $datas
     * @return array<string,mixed>
     */
    public function uploadMedia(string $filePath, array $datas = []): array
    {
        return (new Uploader($this))->upload($filePath, $datas);
    }

    // ---------------------------------------------------------------------
    // Chyro integration helpers
    // ---------------------------------------------------------------------

    /**
     * Find a ScreenOver media by its Chyro media ID (media_id from the Chyro webhook).
     *
     * Chyro sends a "media" webhook event that contains a `media_id` field. ScreenOver
     * stores that value in `metadata.chyroMediaId` when it processes the webhook. This
     * helper queries that JSON field so client code never has to know about the internal
     * storage detail.
     *
     * Returns the matching media document, or null when none is found.
     *
     * Equivalent curl:
     *   curl "https://DOMAIN/api/media?where[metadata.chyroMediaId][equals]=MEDIA_ID&limit=1" \
     *        -H "Authorization: users API-Key YOUR_API_KEY"
     *
     * @return array<string,mixed>|null
     */
    public function findMediaByChyroId(string $chyroMediaId): ?array
    {
        $results = $this->get('media', [
            'where' => ['metadata.chyroMediaId' => ['equals' => $chyroMediaId]],
            'limit' => 1,
        ]);

        // get() returns a flat list when shortCut is true (default).
        $docs = is_array($results) && isset($results[0]) ? $results : [];
        return $docs[0] ?? null;
    }

    /**
     * Find a ScreenOver media by its Chyro program ID (program_id from the Chyro webhook).
     *
     * Chyro sends a "program" webhook event whose `program_id` is stored in
     * `metadata.chyroProgramId`. The program event is typically received before the
     * "media" event, so this lookup works even when the file has not yet been attached.
     *
     * Returns the matching media document, or null when none is found.
     *
     * Equivalent curl:
     *   curl "https://DOMAIN/api/media?where[metadata.chyroProgramId][equals]=PROG_ID&limit=1" \
     *        -H "Authorization: users API-Key YOUR_API_KEY"
     *
     * @return array<string,mixed>|null
     */
    public function findMediaByChyroProgramId(string $chyroProgramId): ?array
    {
        $results = $this->get('media', [
            'where' => ['metadata.chyroProgramId' => ['equals' => $chyroProgramId]],
            'limit' => 1,
        ]);

        $docs = is_array($results) && isset($results[0]) ? $results : [];
        return $docs[0] ?? null;
    }

    /**
     * Reset the HTTP layer (kept for Mediative API compatibility).
     */
    public function reset(): self
    {
        $this->http = new Client();
        if (!$this->secure) {
            $this->http->disableSecure();
        }
        if ($this->token !== null) {
            $header = $this->authMode === self::AUTH_API_KEY ? 'users API-Key ' : 'JWT ';
            $this->http->setHeader('Authorization', $header . $this->token);
        }
        if ($this->project !== null) {
            $this->http->setHeader('x-project-id', $this->project);
        }
        return $this;
    }

    /**
     * Close the client (kept for Mediative API compatibility; no persistent handle to free).
     */
    public function close(): self
    {
        return $this;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function baseUrl(): string
    {
        $isLocal = str_starts_with($this->domain, 'localhost') || str_starts_with($this->domain, '127.0.0.1');
        $scheme = $isLocal ? 'http' : 'https';
        return $scheme . '://' . $this->domain . '/api';
    }

    private function url(string $resource, string $queryString = ''): string
    {
        $url = $this->baseUrl() . '/' . ltrim($resource, '/');
        return $queryString !== '' ? $url . '?' . $queryString : $url;
    }

    /**
     * If an id was passed as the options argument (int, UUID string or ['id' => ...]),
     * append it to the resource path and return the new path.
     *
     * @param array<string,mixed>|string|int $options
     */
    private function extractId(string $resource, &$options, bool $autoMap): string
    {
        if (!$autoMap) {
            return $resource;
        }

        if ((is_string($options) && $this->looksLikeId($options)) || is_int($options)) {
            $resource .= '/' . $options;
            $options = [];
        } elseif (is_array($options) && isset($options['id'])) {
            $resource .= '/' . $options['id'];
            unset($options['id']);
        }

        return $resource;
    }

    /**
     * @param string|int $value
     */
    private function looksLikeId($value): bool
    {
        if (is_int($value)) {
            return true;
        }
        // Numeric ids or UUID-like strings.
        return (bool) preg_match('/^\d+$/', $value)
            || (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * Inject the active project on creation for project-scoped collections.
     *
     * @param array<string,mixed> $datas
     * @return array<string,mixed>
     */
    private function injectProject(string $resource, array $datas): array
    {
        $slug = strtok($resource, '/?');
        if (in_array($slug, self::PROJECT_SCOPED, true) && !isset($datas['project']) && $this->project !== null) {
            $datas['project'] = $this->project;
        }
        return $datas;
    }

    /**
     * Inject the active-project filter into the read options for project-scoped collections.
     *
     * Mirrors injectProject() (used on creation) for reads. Skipped when:
     *   - the caller opted out ($allProjects = true),
     *   - no project is currently active,
     *   - the collection is not project-scoped.
     *
     * The condition is forwarded to the OptionParser as an extra "AND" so it never
     * clobbers a user-supplied where.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function injectProjectFilter(string $resource, array $options, bool $allProjects): array
    {
        if ($allProjects || $this->project === null) {
            return $options;
        }

        $slug = strtok(ltrim($resource, '/'), '/?');
        if (!in_array($slug, self::PROJECT_SCOPED, true)) {
            return $options;
        }

        $options['_projectFilter'] = ['project' => ['equals' => $this->project]];

        return $options;
    }

    /**
     * Force visibility=public while preserving caller-supplied filters.
     *
     * Supports both legacy string "where" conditions and native Payload where arrays.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function mergePublicCategoryFilter(array $options): array
    {
        $publicFilter = ['visibility' => ['equals' => 'public']];

        if (!array_key_exists('where', $options) || $options['where'] === '' || $options['where'] === []) {
            $options['where'] = $publicFilter;
            return $options;
        }

        if (is_string($options['where'])) {
            $options['where'] .= ';visibility=public';
            return $options;
        }

        if (is_array($options['where'])) {
            $where = $options['where'];
            if (isset($where['and']) && is_array($where['and'])) {
                $where['and'][] = $publicFilter;
            } else {
                $where = ['and' => [$where, $publicFilter]];
            }
            $options['where'] = $where;
            return $options;
        }

        throw new ApiException('Invalid "where" option: expected string or array.', 400);
    }

    /**
     * Normalise a PayloadCMS response to mimic the legacy Mediative shape.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>|array<int,mixed>
     */
    private function normalize(array $response, bool $shortCut)
    {
        $this->rememberPagination($response);

        if (!$shortCut) {
            return $response;
        }
        if (array_key_exists('doc', $response) && is_array($response['doc'])) {
            return $response['doc'];
        }
        if (array_key_exists('docs', $response) && is_array($response['docs'])) {
            return $response['docs'];
        }
        return $response;
    }

    /**
     * Store pagination fields from list responses only; clear otherwise.
     *
     * @param array<string,mixed> $response
     */
    private function rememberPagination(array $response): void
    {
        if (!array_key_exists('docs', $response) || !is_array($response['docs'])) {
            $this->lastPagination = null;
            return;
        }

        $this->lastPagination = array_filter([
            'totalDocs' => $response['totalDocs'] ?? null,
            'totalPages' => $response['totalPages'] ?? null,
            'page' => $response['page'] ?? null,
            'limit' => $response['limit'] ?? null,
            'hasNextPage' => $response['hasNextPage'] ?? null,
            'hasPrevPage' => $response['hasPrevPage'] ?? null,
        ], static fn ($value): bool => $value !== null);
    }
}
