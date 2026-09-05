<?php
/**
 * The one place FreeITSM's version number is written down.
 *
 * Requested in discussion #92: an operator should be able to open a screen and
 * read which version they are running, rather than "whatever main looked like on
 * some Tuesday". Bumped as step 2 of the release procedure in RELEASING.md, in
 * the same commit that gets tagged.
 *
 * ⚠️ This must live in a file the application SHIPS. It must never move into
 * config.php: that file belongs to the operator, Docker copies over it, and
 * putting anything the application depends on there took every page down in #129.
 *
 * The number here is the source of truth for the System screen, Debug Tools, and
 * anywhere else the version is shown. It is not read from git, because a Docker
 * image has no git history to read.
 */

if (!defined('FREEITSM_VERSION')) {
    define('FREEITSM_VERSION', '1.1.0');
}

/** The version as displayed: "1.0.0". */
function freeitsmVersion(): string
{
    return FREEITSM_VERSION;
}

/**
 * The matching git tag, "v1.0.0" — the tag is v-prefixed, the version is not.
 * Useful for linking to the release notes on GitHub.
 */
function freeitsmVersionTag(): string
{
    return 'v' . FREEITSM_VERSION;
}

/** Link to this version's release notes on GitHub. */
function freeitsmReleaseUrl(): string
{
    return 'https://github.com/edmozley/freeitsm/releases/tag/' . freeitsmVersionTag();
}
