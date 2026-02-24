<?php

namespace App\Controller;

use App\Entity\Author;
use App\Entity\Exposure;
use App\Entity\Session;
use App\Entity\Setup;
use App\Entity\Target;
use App\Entity\WishListEntry;
use App\Repository\ObservatoryRepository;
use App\Repository\TargetRepository;
use App\Repository\WishListEntryRepository;
use App\Service\AlpacaClient;
use App\Service\AstroNightService;
use App\Service\AstropyClient;
use App\Service\ProgressTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class APIController extends AbstractController
{
    #[Route('/api/stats', name: 'api-stats')]
    public function apiStats(EntityManagerInterface $em): Response
    {
        return $this->json([
            "targets"   =>        $em->getRepository(Target::class)->count([]),
            "sessions"  =>        $em->getRepository(Session::class)->count([]),
            "lights"    =>        $em->getRepository(Exposure::class)->count(["type" => "LIGHT"]),
        ]);
    }
    #[Route('/api/author-stats/{author}', name: 'api-author-stats')]
    public function apiAuthorStats(Author $author, EntityManagerInterface $em): Response
    {
        return $this->json([
            "imagecount"   =>        $author->getAstrobinImageCount(),
            "imageindex"   =>        $author->getAstrobinImageIndex(),
            "views"   =>        $author->getAstrobinViews(),
            "likes"   =>        $author->getAstrobinLikes(),
            "followers"   =>        $author->getAstrobinFollowersCount(),
            "following"   =>        $author->getAstrobinFollowingCount(),
        ]);
    }

    #[Route('/api/alpaca/status', name: 'api-alpaca-status')]
    public function alpacaStatus(AlpacaClient $alpaca): Response
    {
        return $this->json($alpaca->getDevicesStatus());
    }

    #[Route('/api/widget', name: 'api-widget', methods: ['GET'])]
    public function apiWidget(
        Request $request,
        ObservatoryRepository $obsRepo,
        EntityManagerInterface $em,
        AstropyClient $astropy,
        AstroNightService $astroNight,
        TargetRepository $targetRepo
    ): Response {
        // Resolve observatory
        $obsId = $request->query->get('obs');
        $obs   = $obsId
            ? $obsRepo->find((int) $obsId)
            : $obsRepo->findOneBy(['favorite' => true]);

        if (!$obs || !$obs->getLat() || !$obs->getLon()) {
            return $this->json(['error' => 'No observatory configured'], 404);
        }

        $lat     = (float) $obs->getLat();
        $lon     = (float) $obs->getLon();
        $horizon = (float) ($obs->getAltitudeHorizon() ?? 20);

        // Weather
        $forecastRaw  = json_decode($astropy->forecast($lat, $lon), true);
        $weather      = $astroNight->computeWeatherAlert($forecastRaw ?? []);

        // Wishlist targets with RA/Dec
        $targets = $targetRepo->findWishlistWithCoordinates();

        $baseDate = new \DateTimeImmutable('today midnight UTC');
        $results  = [];

        foreach ($targets as $t) {
            $raDeg = $t->getRa() * 15.0; // hours → degrees
            $night = $astroNight->computeNight($lat, $lon, $horizon, $raDeg, $t->getDec(), $baseDate);

            if ($night['usefulH'] > 0) {
                $results[] = [
                    'id'          => $t->getId(),
                    'name'        => $t->getName(),
                    'type'        => $t->getType(),
                    'usefulH'     => $night['usefulH'],
                    'windowStart' => $night['windowStart'],
                    'windowEnd'   => $night['windowEnd'],
                ];
            }
        }

        usort($results, fn($a, $b) => $b['usefulH'] <=> $a['usefulH']);
        $results = array_slice($results, 0, 5);

        return $this->json([
            'obs'          => ['name' => $obs->getName(), 'lat' => $lat, 'lon' => $lon],
            'weather'      => $weather,
            'targets'      => $results,
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/api/evening-alerts', name: 'api_evening_alerts', methods: ['GET'])]
    public function apiEveningAlerts(
        ObservatoryRepository $obsRepo,
        EntityManagerInterface $em,
        AstropyClient $astropy,
        AstroNightService $astroNight,
        ProgressTrackingService $progress
    ): Response {
        $baseDate = new \DateTimeImmutable('today midnight UTC');

        // Framing entries grouped by setup
        $wishListEntries = $em->getRepository(WishListEntry::class)->findAll();
        $framingsBySetup = [];
        foreach ($wishListEntries as $wle) {
            $setup = $wle->getSetup();
            if ($setup === null) {
                continue;
            }
            $sid = $setup->getId();
            if (!isset($framingsBySetup[$sid])) {
                $framingsBySetup[$sid] = ['setup' => $setup, 'entries' => []];
            }
            $framingsBySetup[$sid]['entries'][] = $wle;
        }

        // Accumulated seconds & goals per target per filter
        $accumulated = $progress->getAccumulatedAll();
        $goals       = $progress->getGoalsAll();

        // Weather from favorite observatory
        $weather = ['favorable' => false, 'cloud_avg' => 0, 'wind_max' => 0.0, 'precip' => 0.0, 'score_avg' => 0];
        $favObs  = $obsRepo->findOneBy(['favorite' => true]);
        if ($favObs && $favObs->getLat() && $favObs->getLon()) {
            $forecastRaw = json_decode($astropy->forecast($favObs->getLat(), $favObs->getLon()), true);
            $weather     = $astroNight->computeWeatherAlert($forecastRaw ?? []);
        }

        $setupsResult = [];
        foreach ($framingsBySetup as $data) {
            /** @var Setup $setup */
            $setup = $data['setup'];
            $obs   = $setup->getObservatory();
            if (!$obs || !$obs->getLat() || !$obs->getLon()) {
                continue;
            }

            $lat     = (float) $obs->getLat();
            $lon     = (float) $obs->getLon();
            $horizon = (float) ($obs->getAltitudeHorizon() ?? 20);

            $nightBounds = $astroNight->getNightBounds($lat, $lon, $baseDate);

            $topTargets = [];
            foreach ($data['entries'] as $wle) {
                /** @var WishListEntry $wle */
                $t = $wle->getTarget();
                if (!$t || !$t->getRa() || !$t->getDec()) {
                    continue;
                }

                $raDeg = $t->getRa() * 15.0;
                $night = $astroNight->computeNight($lat, $lon, $horizon, $raDeg, $t->getDec(), $baseDate);

                if ($night['usefulH'] <= 0) {
                    continue;
                }

                $tid      = $t->getId();
                $deficitH = $progress->computeDeficitHours($goals[$tid] ?? [], $accumulated[$tid] ?? []);
                $score    = $astroNight->priorityScore(
                    $night['usefulH'],
                    $night['moonPhase'],
                    $night['minSep'],
                    $deficitH,
                    $t->isNarrowbandType()
                );

                $topTargets[] = $this->buildTargetPayload($t, $night, $deficitH, $score);
            }

            usort($topTargets, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);

            $setupsResult[] = [
                'id'           => $setup->getId(),
                'name'         => $setup->getName(),
                'obs_lat'      => $lat,
                'obs_lon'      => $lon,
                'night_bounds' => [
                    'dusk' => $nightBounds['dusk']?->format(\DateTimeInterface::ATOM),
                    'dawn' => $nightBounds['dawn']?->format(\DateTimeInterface::ATOM),
                ],
                'top_targets'  => array_slice($topTargets, 0, 5),
            ];
        }

        return $this->json([
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'weather'      => $weather,
            'setups'       => $setupsResult,
        ]);
    }

    #[Route('/api/night-planner', name: 'api_night_planner', methods: ['GET'])]
    public function apiNightPlanner(
        Request $request,
        ObservatoryRepository $obsRepo,
        EntityManagerInterface $em,
        AstroNightService $astroNight,
        ProgressTrackingService $progress,
        WishListEntryRepository $wleRepo
    ): Response {
        // Parse date param
        $dateParam = $request->query->get('date', (new \DateTimeImmutable())->format('Y-m-d'));
        try {
            $baseDate = new \DateTimeImmutable($dateParam . ' midnight UTC');
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date format, expected YYYY-MM-DD'], 400);
        }

        // Resolve setup
        $setupId     = $request->query->get('setup_id');
        $setup       = $setupId ? $em->getRepository(Setup::class)->find((int) $setupId) : null;
        $wishlistOnly = (bool) $request->query->get('wishlist_only', 0);

        // Observatory from setup or favorite
        if ($setup && $setup->getObservatory()) {
            $obs = $setup->getObservatory();
        } else {
            $obs = $obsRepo->findOneBy(['favorite' => true]);
        }

        if (!$obs || !$obs->getLat() || !$obs->getLon()) {
            return $this->json(['error' => 'No observatory configured'], 404);
        }

        $lat     = (float) $obs->getLat();
        $lon     = (float) $obs->getLon();
        $horizon = (float) ($obs->getAltitudeHorizon() ?? 20);

        $nightBounds = $astroNight->getNightBounds($lat, $lon, $baseDate);

        // Targets query
        $dql = 'SELECT t FROM App\Entity\Target t
                WHERE t.ra IS NOT NULL AND t.dec IS NOT NULL'
             . ($wishlistOnly ? ' AND t.wishlist = true' : '')
             . ' ORDER BY t.name ASC';
        /** @var Target[] $targets */
        $targets = $em->createQuery($dql)->getResult();

        // Framing map: target_id → framing data (remap setupId → setup_id for API)
        $framingMap = [];
        foreach ($wleRepo->getFramingMap() as $tid => $f) {
            $framingMap[$tid] = [
                'ra'       => $f['ra'],
                'dec'      => $f['dec'],
                'rotation' => $f['rotation'],
                'setup_id' => $f['setupId'],
            ];
        }

        $accumulated = $progress->getAccumulatedAll();
        $goals       = $progress->getGoalsAll();

        $results = [];
        foreach ($targets as $t) {
            $tid = $t->getId();

            // Filter by setup if specified
            if ($setup !== null) {
                $framing = $framingMap[$tid] ?? null;
                if ($framing === null || $framing['setup_id'] !== $setup->getId()) {
                    continue;
                }
            }

            $raDeg = $t->getRa() * 15.0;
            $night = $astroNight->computeNight($lat, $lon, $horizon, $raDeg, $t->getDec(), $baseDate);

            if ($night['usefulH'] <= 0) {
                continue;
            }

            $deficitH = $progress->computeDeficitHours($goals[$tid] ?? [], $accumulated[$tid] ?? []);
            $score    = $astroNight->priorityScore(
                $night['usefulH'],
                $night['moonPhase'],
                $night['minSep'],
                $deficitH,
                $t->isNarrowbandType()
            );

            $payload             = $this->buildTargetPayload($t, $night, $deficitH, $score);
            $payload['wishlist'] = $t->isWishlist();
            $payload['framing']  = $framingMap[$tid] ?? null;
            $results[]           = $payload;
        }

        usort($results, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);

        // Setup info
        $setupInfo = null;
        if ($setup) {
            $setupInfo = [
                'id'              => $setup->getId(),
                'name'            => $setup->getName(),
                'obs_lat'         => $lat,
                'obs_lon'         => $lon,
                'slew_min'        => $setup->getSlewTimeMin() ?? 5,
                'af_time_min'     => $setup->getAutofocusTimeMin() ?? 10,
                'af_interval_min' => $setup->getAutofocusIntervalMin() ?? 60,
                'flip_min'        => $setup->getMeridianFlipTimeMin() ?? 5,
                'min_shoot_min'   => $setup->getMinShootTimeMin() ?? 30,
            ];
        }

        return $this->json([
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'date'         => $baseDate->format('Y-m-d'),
            'night_bounds' => [
                'dusk' => $nightBounds['dusk']?->format(\DateTimeInterface::ATOM),
                'dawn' => $nightBounds['dawn']?->format(\DateTimeInterface::ATOM),
            ],
            'setup'        => $setupInfo,
            'targets'      => $results,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildTargetPayload(Target $t, array $night, float $deficitH, float $score): array
    {
        return [
            'id'             => $t->getId(),
            'name'           => $t->getName(),
            'type'           => $t->getType() ?? '',
            'ra'             => $t->getRa(),
            'dec'            => $t->getDec(),
            'useful_h'       => $night['usefulH'],
            'window_start'   => $night['windowStart'],
            'window_end'     => $night['windowEnd'],
            'moon_sep'       => $night['minSep'],
            'moon_phase'     => $night['moonPhase'],
            'deficit_h'      => round($deficitH, 2),
            'priority_score' => round($score, 3),
        ];
    }

}
