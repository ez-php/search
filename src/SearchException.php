<?php

declare(strict_types=1);

namespace EzPhp\Search;

use EzPhp\Contracts\EzPhpException;

/**
 * Class SearchException
 *
 * Thrown when a search operation fails — driver communication errors,
 * unexpected responses, or misconfiguration.
 *
 * @package EzPhp\Search
 */
final class SearchException extends EzPhpException
{
}
