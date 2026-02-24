<?php

namespace App\Tests;

use App\Entity\Notification;
use App\Entity\Observatory;
use App\Entity\Session;
use App\Entity\Target;
use App\Service\AlpacaClient;
use App\Service\AstropyClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Tests fonctionnels : routes POST, AJAX JSON et suppressions avec CSRF.
 *
 * Exécution : docker compose exec php php bin/phpunit --filter FunctionalTest --testdox
 */
class FunctionalTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?string $appConfigBackup = null;

    /** @var int[] IDs des entités créées pour nettoyage */
    private array $createdTargetIds = [];
    private array $createdSessionIds = [];
    private array $createdNotificationIds = [];
    private array $createdObservatoryIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Sauvegarder app_config.json avant les tests
        $configPath = static::getContainer()->getParameter('kernel.project_dir') . '/var/app_config.json';
        if (file_exists($configPath)) {
            $this->appConfigBackup = file_get_contents($configPath);
        }

        $astropyMock = $this->createMock(AstropyClient::class);
        $astropyMock->method('forecast')->willReturn('{"series":[]}');
        static::getContainer()->set(AstropyClient::class, $astropyMock);

        $alpacaMock = $this->createMock(AlpacaClient::class);
        $alpacaMock->method('getSafetyMonitorConfig')->willReturn([]);
        $alpacaMock->method('getSwitchConfig')->willReturn([]);
        $alpacaMock->method('getDevicesStatus')->willReturn([]);
        static::getContainer()->set(AlpacaClient::class, $alpacaMock);

        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        // Nettoyage dans l'ordre inverse des dépendances
        foreach ($this->createdSessionIds as $id) {
            $entity = $this->em->find(Session::class, $id);
            if ($entity) {
                $this->em->remove($entity);
            }
        }
        foreach ($this->createdNotificationIds as $id) {
            $entity = $this->em->find(Notification::class, $id);
            if ($entity) {
                $this->em->remove($entity);
            }
        }
        foreach ($this->createdTargetIds as $id) {
            $entity = $this->em->find(Target::class, $id);
            if ($entity) {
                $this->em->remove($entity);
            }
        }
        foreach ($this->createdObservatoryIds as $id) {
            $entity = $this->em->find(Observatory::class, $id);
            if ($entity) {
                $this->em->remove($entity);
            }
        }
        if (!empty($this->createdSessionIds) || !empty($this->createdNotificationIds)
            || !empty($this->createdTargetIds) || !empty($this->createdObservatoryIds)) {
            $this->em->flush();
        }

        // Restaurer app_config.json après les tests
        if ($this->appConfigBackup !== null) {
            $configPath = static::getContainer()->getParameter('kernel.project_dir') . '/var/app_config.json';
            file_put_contents($configPath, $this->appConfigBackup);
        }

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestTarget(string $name = '__test__target'): Target
    {
        $target = new Target();
        $target->setName($name);
        $this->em->persist($target);
        $this->em->flush();
        $this->createdTargetIds[] = $target->getId();

        return $target;
    }

    private function createTestSession(?Target $target = null): Session
    {
        if (!$target) {
            $target = $this->createTestTarget();
        }
        $session = new Session();
        $session->setTarget($target);
        $this->em->persist($session);
        $this->em->flush();
        $this->createdSessionIds[] = $session->getId();

        return $session;
    }

    private function createTestNotification(): Notification
    {
        $notif = new Notification();
        $notif->setType('evening_alert');
        $notif->setTitle('__test__notification');
        $notif->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($notif);
        $this->em->flush();
        $this->createdNotificationIds[] = $notif->getId();

        return $notif;
    }

    private function mockCsrfValid(): void
    {
        $csrfMock = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfMock->method('isTokenValid')->willReturn(true);
        $csrfMock->method('getToken')->willReturnCallback(
            fn(string $id) => new CsrfToken($id, 'mock_token')
        );
        static::getContainer()->set('security.csrf.token_manager', $csrfMock);
    }

    // ── Tier 1 : Sans fixtures, sans CSRF ────────────────────────────────────

    public function testSettingsLocaleEn(): void
    {
        $this->client->request('POST', '/settings/locale/en');
        self::assertResponseRedirects();
    }

    public function testSettingsLocaleFr(): void
    {
        $this->client->request('POST', '/settings/locale/fr');
        self::assertResponseRedirects();
    }

    public function testSettingsThemeDark(): void
    {
        $this->client->request('POST', '/settings/theme/dark');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('dark', $data['theme']);
    }

    public function testSettingsThemeLight(): void
    {
        $this->client->request('POST', '/settings/theme/light');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('light', $data['theme']);
    }

    public function testSettingsSaveNotifications(): void
    {
        $this->client->request('POST', '/settings/section/notifications', [
            'emails'            => 'test@example.com',
            'weather_cloud_max' => '50',
            'weather_wind_max'  => '10',
            'weather_precip_max'=> '0.5',
            'min_useful_hours'  => '2',
            'min_moon_sep'      => '30',
        ]);
        self::assertResponseRedirects();
    }

    public function testSettingsSaveSessionsRoot(): void
    {
        $this->client->request('POST', '/settings/section/sessions_root', [
            'sessions_root' => '/tmp',
        ]);
        self::assertResponseRedirects();
    }

    public function testSettingsSaveSessionsRootReset(): void
    {
        $this->client->request('POST', '/settings/section/sessions_root', [
            'sessions_root' => '',
        ]);
        self::assertResponseRedirects();
    }

    public function testApiNotificationsReadAll(): void
    {
        $this->client->request('POST', '/api/notifications/read-all');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
    }

    // ── Tier 2 : Avec fixtures, sans CSRF ────────────────────────────────────

    public function testSessionToggleExclude(): void
    {
        $session = $this->createTestSession();
        self::assertFalse($session->isExcludeFromProgress());

        $this->client->request('POST', '/session/' . $session->getId() . '/toggle-progress-exclude');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['excluded']);
    }

    public function testSessionSaveNotes(): void
    {
        $session = $this->createTestSession();

        $this->client->request('POST', '/session/' . $session->getId() . '/notes', [
            'notes' => 'Test notes content',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
    }

    public function testTargetGoalSave(): void
    {
        $target = $this->createTestTarget();

        $this->client->request('POST', '/target/' . $target->getId() . '/goal', [
            'filter' => 'L',
            'hours'  => '5',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }

    public function testTargetGoalDeleteZero(): void
    {
        $target = $this->createTestTarget();

        // Créer un goal d'abord
        $this->client->request('POST', '/target/' . $target->getId() . '/goal', [
            'filter' => 'L',
            'hours'  => '5',
        ]);
        self::assertResponseIsSuccessful();

        // Supprimer en envoyant hours=0
        $this->client->request('POST', '/target/' . $target->getId() . '/goal', [
            'filter' => 'L',
            'hours'  => '0',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }

    public function testApiNotificationMarkRead(): void
    {
        $notif = $this->createTestNotification();
        self::assertNull($notif->getReadAt());

        $this->client->request('POST', '/api/notifications/' . $notif->getId() . '/read');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        // Vérifier en DB
        $this->em->refresh($notif);
        self::assertNotNull($notif->getReadAt());
    }

    // ── Tier 3 : Suppressions avec CSRF mocké ────────────────────────────────

    public function testTargetDelete(): void
    {
        $this->mockCsrfValid();
        $target = $this->createTestTarget('__test__delete_target');
        $targetId = $target->getId();

        $this->client->request('POST', '/target/delete/' . $targetId, [
            '_token' => 'mock_token',
        ]);
        self::assertResponseRedirects();

        // Vérifier suppression en DB
        $deleted = $this->em->find(Target::class, $targetId);
        self::assertNull($deleted);

        // Retirer de la liste de nettoyage (déjà supprimé)
        $this->createdTargetIds = array_diff($this->createdTargetIds, [$targetId]);
    }

    public function testSessionDelete(): void
    {
        $this->mockCsrfValid();
        $target = $this->createTestTarget('__test__delete_session_target');
        $session = $this->createTestSession($target);
        $sessionId = $session->getId();

        $this->client->request('POST', '/session/delete/' . $sessionId, [
            '_token' => 'mock_token',
        ]);
        self::assertResponseRedirects();

        // Vérifier suppression en DB
        $deleted = $this->em->find(Session::class, $sessionId);
        self::assertNull($deleted);

        // Retirer de la liste de nettoyage
        $this->createdSessionIds = array_diff($this->createdSessionIds, [$sessionId]);
    }

    // ── Tier 3b : Scan API ─────────────────────────────────────────────────────

    public function testScanTargetsApply(): void
    {
        $this->client->request('POST', '/api/scan/targets/apply', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['add' => ['/tmp/__test__ScanTarget'], 'remove' => []]));
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        // Clean up created target
        $target = $this->em->getRepository(Target::class)->findOneBy(['name' => '__test__ScanTarget']);
        if ($target) {
            $this->createdTargetIds[] = $target->getId();
        }
    }

    public function testScanSessionsApply(): void
    {
        $target = $this->createTestTarget('__test__scan_sessions_target');

        $this->client->request('POST', '/api/scan/sessions/' . $target->getId() . '/apply', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['add' => ['/tmp/20260101___test__session'], 'remove' => []]));
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        // Clean up created session
        $session = $this->em->getRepository(Session::class)->findOneBy(['target' => $target->getId()]);
        if ($session) {
            $this->createdSessionIds[] = $session->getId();
        }
    }

    // ── Tier 4 : Formulaire Symfony ──────────────────────────────────────────

    public function testObservatoryCreate(): void
    {
        // GET pour récupérer le formulaire (et le token CSRF auto-généré)
        $crawler = $this->client->request('GET', '/observatories');
        self::assertResponseIsSuccessful();

        // Soumettre le formulaire via le bouton submit du form create
        $button = $crawler->filter('#push');
        $form = $button->form([
            'observatory[name]' => '__test__observatory',
            'observatory[lat]'  => '48.8566',
            'observatory[lon]'  => '2.3522',
        ]);

        $this->client->submit($form);
        self::assertResponseRedirects();

        // Vérifier en DB
        $obs = $this->em->getRepository(Observatory::class)->findOneBy(['name' => '__test__observatory']);
        self::assertNotNull($obs);
        $this->createdObservatoryIds[] = $obs->getId();
    }
}
