<?php
namespace App\Controller;

use App\Entity\Observatory;
use App\Entity\Setup;
use App\Entity\Target;
use App\Repository\ObservatoryRepository;
use App\Repository\TargetRepository;
use App\Repository\WishListEntryRepository;
use App\Service\AlpacaClient;
use App\Service\AppConfig;
use App\Service\AstroNightService;
use App\Service\AstropyClient;
use App\Service\ProgressTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(AstropyClient $astropyClient, ObservatoryRepository $obsRepo, AlpacaClient $alpaca, EntityManagerInterface $em, AppConfig $config, ProgressTrackingService $progress, TargetRepository $targetRepo, AstroNightService $astroNight, WishListEntryRepository $wleRepo): Response
    {
        $obs = $obsRepo->findOneBy(['favorite' => true]);

        // Independent widgets (Alpaca, Documents, Counters) always shown
        $sm     = $alpaca->getSafetyMonitorConfig(0);
        $switch = $alpaca->getSwitchConfig(0);

        if (!$obs) {
            return $this->render('dashboard/index.html.twig', [
                'noObservatory' => true,
                'sm'            => $sm,
                'switch'        => $switch,
            ]);
        }

        try {
            $forecastData = json_decode($astropyClient->forecast($obs->getLat(), $obs->getLon()), true);
        } catch (\Throwable) {
            $forecastData = null;
        }

        $wishlistTargets = $targetRepo->findWishlistWithCoordinates();

        // Accumulated seconds & goals per target per filter
        $accumulated = $progress->getAccumulatedAll();
        $goals       = $progress->getGoalsAll();

        // Framing per target
        $framingMap          = $wleRepo->getFramingMap();
        $setupIdsWithFraming = $wleRepo->getSetupIdsWithFraming();

        $setupsJson = [];
        foreach ($em->getRepository(Setup::class)->findAll() as $s) {
            if (!in_array($s->getId(), $setupIdsWithFraming)) continue;
            $setupsJson[] = [
                'id'           => $s->getId(),
                'name'         => $s->getName(),
                'obsLat'       => $s->getObservatory()?->getLat(),
                'obsLon'       => $s->getObservatory()?->getLon(),
                'obsHorizon'   => $s->getObservatory()?->getAltitudeHorizon() ?? 20,
                'slewMin'      => $s->getSlewTimeMin() ?? 5,
                'afTimeMin'    => $s->getAutofocusTimeMin() ?? 10,
                'afIntervalMin'=> $s->getAutofocusIntervalMin() ?? 60,
                'flipMin'      => $s->getMeridianFlipTimeMin() ?? 5,
                'minShootMin'  => $s->getMinShootTimeMin() ?? 30,
            ];
        }

        $wishlistJson = array_map(function (Target $t) use ($accumulated, $goals, $framingMap, $progress) {
            $tid      = $t->getId();
            $deficitH = $progress->computeDeficitHours($goals[$tid] ?? [], $accumulated[$tid] ?? []);
            return [
                'id'       => $tid,
                'name'     => $t->getName(),
                'ra'       => $t->getRa(),
                'dec'      => $t->getDec(),
                'type'     => $t->getType() ?? '',
                'deficitH' => round($deficitH, 2),
                'framing'  => $framingMap[$tid] ?? null,
            ];
        }, $wishlistTargets);

        return $this->render('dashboard/index.html.twig', [
            'forecast'        => $forecastData,
            'obs'             => $obs,
            'allObs'          => $obsRepo->findAll(),
            'sm'              => $sm,
            'switch'          => $switch,
            'weatherAlert'    => $forecastData ? $astroNight->computeWeatherAlert($forecastData, $config->getSection('notifications')) : null,
            'wishlistJson'    => json_encode($wishlistJson),
            'setupsJson'      => json_encode($setupsJson),
        ]);
    }

    #[Route('/dashboard/obs/{id}', name: 'dashboard_obs', methods: ['GET'])]
    public function obsData(Observatory $observatory, AstropyClient $astropyClient): JsonResponse
    {
        try {
            $forecast = json_decode($astropyClient->forecast($observatory->getLat(), $observatory->getLon()), true);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Weather service unavailable'], 503);
        }
        return new JsonResponse([
            'forecast' => $forecast,
            'name'     => $observatory->getName(),
            'city'     => $observatory->getCity() ?? '',
            'live'     => $observatory->getLive() ?? '',
        ]);
    }

}
