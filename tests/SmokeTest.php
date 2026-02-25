<?php

namespace App\Tests;

use App\Service\AlpacaClient;
use App\Service\AstropyClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests : vérifie que les routes principales répondent HTTP 200
 * sans erreur 500, en mockant les services HTTP externes.
 *
 * Exécution : docker compose exec php php bin/phpunit
 */
class SmokeTest extends WebTestCase
{
    // Forecast minimal valide pour DashboardController::computeWeatherAlert()
    private const FORECAST_JSON = '{"series":[]}';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // createClient() boot le kernel — ne pas rappeler createClient() dans les tests
        $this->client = static::createClient();


        // Mock AstropyClient — évite les appels HTTP vers le microservice Python
        $astropyMock = $this->createMock(AstropyClient::class);
        $astropyMock->method('forecast')->willReturn(self::FORECAST_JSON);
        static::getContainer()->set(AstropyClient::class, $astropyMock);

        // Mock AlpacaClient — évite les appels HTTP vers le serveur Alpaca
        $alpacaMock = $this->createMock(AlpacaClient::class);
        $alpacaMock->method('getSafetyMonitorConfig')->willReturn([]);
        $alpacaMock->method('getSwitchConfig')->willReturn([]);
        $alpacaMock->method('getDevicesStatus')->willReturn([]);
        static::getContainer()->set(AlpacaClient::class, $alpacaMock);
    }

    // ── Pages HTML ──────────────────────────────────────────────────────────

    public function testHomePage(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testSessionsPage(): void
    {
        $this->client->request('GET', '/sessions');
        self::assertResponseIsSuccessful();
    }

    public function testTargetsPage(): void
    {
        $this->client->request('GET', '/targets');
        self::assertResponseIsSuccessful();
    }

    public function testNightPlannerPage(): void
    {
        $this->client->request('GET', '/night-planner');
        self::assertResponseIsSuccessful();
    }

    public function testNotificationsPage(): void
    {
        $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
    }

    public function testSettingsPage(): void
    {
        $this->client->request('GET', '/settings');
        self::assertResponseIsSuccessful();
    }

    public function testSetupListPage(): void
    {
        $this->client->request('GET', '/setup/list');
        self::assertResponseIsSuccessful();
    }

    public function testObservatoriesPage(): void
    {
        $this->client->request('GET', '/observatories');
        self::assertResponseIsSuccessful();
    }

    public function testAuthorsPage(): void
    {
        $this->client->request('GET', '/authors');
        self::assertResponseIsSuccessful();
    }

    // ── API JSON ────────────────────────────────────────────────────────────

    public function testApiNotifications(): void
    {
        $this->client->request('GET', '/api/notifications');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testApiNotificationsUnreadCount(): void
    {
        $this->client->request('GET', '/api/notifications/unread-count');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('count', $data);
    }

    public function testApiStats(): void
    {
        $this->client->request('GET', '/api/stats');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testApiWidget(): void
    {
        $this->client->request('GET', '/api/widget');
        // Retourne 404 si aucune observatory favorite — acceptable
        self::assertThat(
            $this->client->getResponse()->getStatusCode(),
            self::logicalOr(self::equalTo(200), self::equalTo(404))
        );
    }

    public function testApiEveningAlerts(): void
    {
        $this->client->request('GET', '/api/evening-alerts');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testApiScanTargets(): void
    {
        $this->client->request('GET', '/api/scan/targets');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('new', $data);
        self::assertArrayHasKey('missing', $data);
    }

}
