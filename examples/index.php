<?php
/*
 * examples/index.php — live CRUD demo (makes real network calls).
 *
 * End-to-end scenario, close to the legacy Mediative index.php:
 * auth -> setProject -> create -> read -> update -> list -> delete -> confirm.
 * Fill in the config constants below, then run:  php examples/index.php
 *
 * For an offline check of the SDK internals (no backend needed), see selftest.php.
 */

/*   CONFIG TEST   */
define('PUB', '***your identifier / email goes here***');
define('API_KEY', '***your API key goes here***');
// Optional: set your project id (uuid). Leave empty to discover it via getProjects()
// or let selectProject() auto-pick it when your account has a single project.
define('PROJECT', '');
// In production you do NOT need a domain: it defaults to screenover.com.
// For local development only, uncomment the next line:
// define('DOMAIN', 'localhost:3000');
/* END CONFIG PART */

// let see in real time what happens
ob_implicit_flush(true);
if (ob_get_level() > 0) {
    ob_end_flush();
}

// include the SDK (use Composer's vendor/autoload.php instead if installed)
require __DIR__ . '/../autoload.php';

use Screenover\Api\ScreenoverApi;

// start a new instance of the API (domain defaults to screenover.com)
$client = new ScreenoverApi(PUB, API_KEY);

// for local development only, point the SDK to your local backend
if (defined('DOMAIN')) {
    $client->setDomain(DOMAIN);
}

// authenticate (API key mode: installs the auth header, no network round-trip)
$client->auth();

// scope created resources to a project (replaces the legacy Mediative "domain")
// if you know the id, use it directly:
//   $client->setCurrentProject(PROJECT);
// otherwise, discover it through the API:
$projects = $client->getProjects();
echo '<h4>Available projects :</h4><ul>';
foreach ($projects as $p) {
    echo '<li>' . $p['id'] . ' &mdash; ' . $p['title'] . '</li>';
}
echo '</ul>';
if (defined('PROJECT') && PROJECT) {
    $client->setCurrentProject(PROJECT);
} else {
    $client->selectProject(); // auto-select when the account has a single project
}
echo '<h4>Active project : ' . $client->getProject() . '</h4>';

// let's prepare datas to add a media (a YouTube source, no file upload needed)
$datas = array(
    'title'  => 'test api',
    'source' => array(
        'type' => 'youtube',
        'url'  => 'https://youtu.be/dQw4w9WgXcQ',
    ),
);

try {
    // request to add a media
    $media = $client->post('media', $datas);
    if (isset($media['id'])) {
        $id = $media['id'];
        echo '<h4>Media added ! <small>#' . $id . '</small></h4>';

        echo '<h4>Media datas : </h4>';
        $query = $client->get('media', $id); // request to get the added media
        var_dump($query);

        echo '<h4>Updating media #' . $id . '...</h4>';
        $datas = array('id' => $id, 'title' => 'updated api');
        $response = $client->put('media', $datas); // request to update the media
        var_dump($response);

        echo '<h4>New media datas : </h4>';
        $query = $client->get('media', $id); // request to get the updated media
        var_dump($query);

        echo '<h4>Listing medias (Mediative-style options) : </h4>';
        $list = $client->get('media', array(
            'where' => 'title%%updated',
            'order' => 'created:DESC',
            'limit' => '0,25',
        ));
        var_dump($list);

        echo '<h4>Deleting #' . $id . ' </h4>';
        $query = $client->delete('media', $id); // request to delete the added media
        var_dump($query);

        echo '<h4>Confirmation...</h4>';
        try {
            $query = $client->get('media', $id); // should raise a NotFoundException
            var_dump($query);
        } catch (Exception $e) {
            echo '<p>Media #' . $id . ' has been deleted.</p>';
        }
    } else {
        throw new Exception('Cannot parse response data'); // media couldn't be added
    }
} catch (Exception $e) {
    echo '<p class="error" style="color:red">' . $e->getMessage() . '</p>';
}

/*
 * Uploading a real file (image, video, pdf...) uses the multi-step GCS flow,
 * fully handled by uploadMedia():
 *
 *   $media = $client->uploadMedia('/path/to/photo.jpg', array(
 *       'title' => 'My photo',
 *   ));
 *   echo $media['id'];
 */
