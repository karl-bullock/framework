<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\ExtensionManager\Tests\integration\SetupComposer;

// Dependencies live at the monorepo root, not inside the extension, so the
// autoloader is resolved the same way every other extension here resolves it.
// Requiring ../../vendor/autoload.php only works in the standalone repository
// layout this extension had before the monorepo move.
$setup = require __DIR__.'/../../../../php-packages/testing/bootstrap/monorepo.php';

$setup->run();

// Unlike other extensions, this one drives composer itself, so its tests need a
// composer project to operate on as well as a forum.
$setupComposer = new SetupComposer();

$setupComposer->run();
