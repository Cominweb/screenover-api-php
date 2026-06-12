<?php

namespace Screenover\Api\Exception;

/**
 * Raised when a legacy Mediative filter cannot be translated into a
 * PayloadCMS query (unknown operator / unrecognised condition).
 *
 * Replaces the previous silent pass-through that returned an unfiltered list.
 */
class UnsupportedFilterException extends ValidationException
{
}
