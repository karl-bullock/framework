<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Flarum\ExtensionManager\Tests\integration\api;

use Flarum\ExtensionManager\Tests\integration\ChangeComposerConfig;
use Flarum\ExtensionManager\Tests\integration\DummyExtensions;
use Flarum\ExtensionManager\Tests\integration\RefreshComposerSetup;
use Flarum\ExtensionManager\Tests\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PreflightTest extends TestCase
{
    use RefreshComposerSetup;
    use ChangeComposerConfig;
    use DummyExtensions;

    #[Test]
    public function requires_admin()
    {
        $response = $this->send(
            $this->request('POST', '/api/extension-manager/preflight', [
                'authenticatedAs' => 2,
            ])
        );

        $this->assertEquals(403, $response->getStatusCode());
    }

    #[Test]
    public function cannot_run_when_no_update_check_ran()
    {
        $this->setComposerConfig([
            'require' => [
                'flarum/core' => '^1.8',
            ],
            'minimum-stability' => 'beta',
        ]);

        $response = $this->send(
            $this->request('POST', '/api/extension-manager/preflight', [
                'authenticatedAs' => 1,
            ])
        );

        $this->assertEquals(409, $response->getStatusCode());
        $this->assertEquals('no_new_major_version', $this->errorDetails($response)['code']);
    }

    #[Test]
    public function reports_that_a_compatible_forum_would_upgrade()
    {
        $this->makeDummyExtensionCompatibleWith('flarum/dummy-compatible-extension', '^1.8 | ^2.0');
        $this->setComposerConfig([
            'require' => [
                'flarum/core' => '^1.8',
                'flarum/dummy-compatible-extension' => '^1.0.0',
            ],
            'minimum-stability' => 'beta',
        ]);

        $data = $this->preflight();

        $this->assertTrue($data['resolves']);
        $this->assertEmpty($data['blocking']);
    }

    /**
     * The reason this exists. Without it the administrator learns which
     * extension is holding them back by starting an upgrade that cannot
     * finish.
     */
    #[Test]
    public function names_the_extension_that_blocks_the_upgrade()
    {
        $this->makeDummyExtensionCompatibleWith('flarum/dummy-incompatible-extension', '^1.8');
        $this->setComposerConfig([
            'require' => [
                'flarum/core' => '^1.8',
                'flarum/dummy-incompatible-extension' => '^1.0.0',
            ],
            'minimum-stability' => 'beta',
        ]);

        $data = $this->preflight();

        $this->assertFalse($data['resolves']);
        $this->assertContains('flarum/dummy-incompatible-extension', $data['blocking']);
    }

    /**
     * A check that changed something would be worse than no check at all: it
     * has to ask the question by editing composer.json, and it has to put it
     * back whatever the answer.
     */
    #[Test]
    public function changes_nothing()
    {
        $this->makeDummyExtensionCompatibleWith('flarum/dummy-incompatible-extension', '^1.8');
        $config = [
            'require' => [
                'flarum/core' => '^1.8',
                'flarum/dummy-incompatible-extension' => '^1.0.0',
            ],
            'minimum-stability' => 'beta',
        ];
        $this->setComposerConfig($config);

        $composerJson = $this->tmpDir().'/composer.json';
        $before = file_get_contents($composerJson);

        $this->preflight();

        $this->assertEquals($before, file_get_contents($composerJson), 'composer.json must be left exactly as it was');
    }

    #[Test]
    public function reports_whether_the_server_can_finish_the_job()
    {
        $this->makeDummyExtensionCompatibleWith('flarum/dummy-compatible-extension', '^1.8 | ^2.0');
        $this->setComposerConfig([
            'require' => [
                'flarum/core' => '^1.8',
                'flarum/dummy-compatible-extension' => '^1.0.0',
            ],
            'minimum-stability' => 'beta',
        ]);

        $environment = $this->preflight()['environment'];

        $this->assertArrayHasKey('memoryLimitSufficient', $environment);
        $this->assertArrayHasKey('diskSufficient', $environment);
        $this->assertIsBool($environment['memoryLimitSufficient']);
        $this->assertIsBool($environment['diskSufficient']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function preflight(): array
    {
        $this->send(
            $this->request('POST', '/api/extension-manager/check-for-updates', [
                'authenticatedAs' => 1,
            ])
        );

        $this->forgetComposerApp();

        $response = $this->send(
            $this->request('POST', '/api/extension-manager/preflight', [
                'authenticatedAs' => 1,
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['data'];
    }
}
