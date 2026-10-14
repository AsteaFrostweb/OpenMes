<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Every service running the application image shares the host's APP_KEY.
 *
 * The backend went without it: it made up its own key inside the container, a
 * recreated container (every image upgrade) made up another, and everything
 * encrypted under the old one — webhook secrets, integration credentials,
 * sessions — became unreadable ("The MAC is invalid", HTTP 500 on the webhook
 * form). The sidecars meanwhile had the host key, so the queue worker could not
 * read what the backend encrypted even without an upgrade.
 */
class DockerComposeAppKeyTest extends TestCase
{
    public function test_every_application_service_gets_the_host_app_key(): void
    {
        $plik = dirname(base_path()).'/docker-compose.yml';

        if (! is_file($plik)) {
            $this->markTestSkipped('docker-compose.yml is not next to the backend in this checkout.');
        }

        $compose = Yaml::parseFile($plik);
        $sprawdzone = [];

        foreach ($compose['services'] ?? [] as $nazwa => $usluga) {
            if (! str_contains((string) ($usluga['image'] ?? ''), 'mes-open/openmes')) {
                continue;
            }

            $sprawdzone[] = $nazwa;
            $this->assertSame('${APP_KEY}', $usluga['environment']['APP_KEY'] ?? null, "Service {$nazwa} does not get APP_KEY from the host .env.");
        }

        $this->assertContains('backend', $sprawdzone);
    }
}
