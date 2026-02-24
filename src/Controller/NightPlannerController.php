<?php
namespace App\Controller;

use App\Entity\Setup;
use App\Entity\Target;
use App\Repository\ObservatoryRepository;
use App\Repository\WishListEntryRepository;
use App\Service\AstroNightService;
use App\Service\Nina\NinaSequenceBuilder;
use App\Service\ProgressTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NightPlannerController extends AbstractController
{
    #[Route('/night-planner', name: 'night_planner')]
    public function index(EntityManagerInterface $em, ObservatoryRepository $obsRepo, ProgressTrackingService $progress, WishListEntryRepository $wleRepo): Response
    {
        $obs = $obsRepo->findOneBy(['favorite' => true]);

        $targets = $em->createQuery(
            'SELECT t FROM App\Entity\Target t
             WHERE t.ra IS NOT NULL AND t.dec IS NOT NULL
             ORDER BY t.name ASC'
        )->getResult();

        // Accumulated seconds & goals per target per filter
        $accumulated = $progress->getAccumulatedAll();
        $goals       = $progress->getGoalsAll();

        // Framing entries (WishListEntry) per target
        $framingMap = $wleRepo->getFramingMap();

        // Build target payload for JS
        $targetsJson = [];
        foreach ($targets as $t) {
            $tid    = $t->getId();
            $deficitH = $progress->computeDeficitHours($goals[$tid] ?? [], $accumulated[$tid] ?? []);
            $targetsJson[] = [
                'id'        => $tid,
                'name'      => $t->getName(),
                'ra'        => $t->getRa(),
                'dec'       => $t->getDec(),
                'type'      => $t->getType() ?? '',
                'wishlist'  => $t->isWishlist(),
                'deficitH'  => $deficitH,
                'framing'   => $framingMap[$tid] ?? null,
            ];
        }

        // Setups that have at least one WishListEntry (framing)
        $setupIdsWithFraming = $wleRepo->getSetupIdsWithFraming();

        // Setups with optical data + overhead params for Night Planner
        $setupsJson = [];
        $setupsForPlanner = [];
        foreach ($em->getRepository(Setup::class)->findAll() as $s) {
            $setupEntry = [
                'id'                   => $s->getId(),
                'name'                 => $s->getName(),
                'sensorWPx'            => $s->getSensorWPx(),
                'sensorHPx'            => $s->getSensorHPx(),
                'pixelSizeUm'          => $s->getPixelSizeUm(),
                'focalMm'              => $s->getFocalMm(),
                'obsLat'               => $s->getObservatory()?->getLat(),
                'obsLon'               => $s->getObservatory()?->getLon(),
                'obsHorizon'           => $s->getObservatory()?->getAltitudeHorizon() ?? 20,
                'slewTimeMin'          => $s->getSlewTimeMin() ?? 5,
                'autofocusTimeMin'     => $s->getAutofocusTimeMin() ?? 10,
                'autofocusIntervalMin' => $s->getAutofocusIntervalMin() ?? 60,
                'meridianFlipTimeMin'  => $s->getMeridianFlipTimeMin() ?? 5,
                'minShootTimeMin'      => $s->getMinShootTimeMin() ?? 30,
                'filtersConfig'        => $s->getFiltersConfig() ?? [],
            ];
            $setupsJson[] = $setupEntry;

            // Only include in planner if it has framing entries
            if (in_array($s->getId(), $setupIdsWithFraming)) {
                $setupsForPlanner[] = array_merge($setupEntry, [
                    'obsName' => $s->getObservatory()?->getName() ?? '—',
                ]);
            }
        }

        return $this->render('night_planner/index.html.twig', [
            'obs'              => $obs,
            'allObs'           => $obsRepo->findAll(),
            'targetsJson'      => json_encode($targetsJson),
            'setupsJson'       => json_encode($setupsJson),
            'setupsForPlanner' => $setupsForPlanner,
        ]);
    }

    #[Route('/night-planner/export-nina', name: 'night_planner_export_nina', methods: ['GET'])]
    public function exportNina(
        Request $request,
        EntityManagerInterface $em,
        ObservatoryRepository $obsRepo,
        AstroNightService $astroNight,
        NinaSequenceBuilder $builder,
        ProgressTrackingService $progress,
        WishListEntryRepository $wleRepo
    ): Response {
        // ── Params ──
        $dateParam = $request->query->get('date', (new \DateTimeImmutable())->format('Y-m-d'));
        try {
            $baseDate = new \DateTimeImmutable($dateParam . ' midnight UTC');
        } catch (\Exception) {
            return new Response('Invalid date', 400);
        }

        $setupId = $request->query->get('setup_id');
        /** @var Setup|null $setup */
        $setup = $setupId ? $em->getRepository(Setup::class)->find((int) $setupId) : null;
        if (!$setup) {
            return new Response('Setup not found', 404);
        }

        $obs = $setup->getObservatory() ?? $obsRepo->findOneBy(['favorite' => true]);
        if (!$obs || !$obs->getLat() || !$obs->getLon()) {
            return new Response('No observatory configured', 404);
        }

        $lat     = (float) $obs->getLat();
        $lon     = (float) $obs->getLon();
        $horizon = (float) ($obs->getAltitudeHorizon() ?? 20);

        // ── Night bounds ──
        $nightBounds = $astroNight->getNightBounds($lat, $lon, $baseDate);
        $dusk = $nightBounds['dusk'] ?? new \DateTimeImmutable($dateParam . ' 20:00 UTC');
        $dawn = $nightBounds['dawn'] ?? new \DateTimeImmutable($dateParam . ' +1 day 05:00 UTC');

        // ── Targets with framings for this setup ──
        $framingMap = $wleRepo->getFramingMap($setup->getId());
        // NinaSequenceBuilder expects 'rotationAngle' key
        foreach ($framingMap as &$f) {
            $f['rotationAngle'] = $f['rotation'];
        }
        unset($f);

        $goals       = $progress->getGoalsAll();
        $accumulated = $progress->getAccumulatedAll();

        // ── Build rows to score ──
        $rows = [];
        $targets = $em->createQuery(
            'SELECT t FROM App\Entity\Target t WHERE t.ra IS NOT NULL AND t.dec IS NOT NULL ORDER BY t.name ASC'
        )->getResult();

        foreach ($targets as $t) {
            $tid     = $t->getId();
            $framing = $framingMap[$tid] ?? null;
            if ($framing === null) {
                continue; // only targets with framings for this setup
            }

            $raDeg = $t->getRa() * 15.0;
            $night = $astroNight->computeNight($lat, $lon, $horizon, $raDeg, $t->getDec(), $baseDate);
            if ($night['usefulH'] <= 0 || !$night['windowStartTs'] || !$night['windowEndTs']) {
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

            $rows[] = [
                'target'  => $t,
                'framing' => $framing,
                'night'   => $night,
                'score'   => $score,
            ];
        }

        // ── Greedy scheduler (port of computeSchedule JS) ──
        $slewMin   = $setup->getSlewTimeMin()         ?? 5;
        $afTimeMin = $setup->getAutofocusTimeMin()    ?? 10;
        $afIntMin  = $setup->getAutofocusIntervalMin() ?? 60;
        $flipMin   = $setup->getMeridianFlipTimeMin()  ?? 5;
        $minShootMin = $setup->getMinShootTimeMin()    ?? 30;

        $schedule = [];
        $cursorTs = $dusk->getTimestamp();
        $endTs    = $dawn->getTimestamp();
        $used     = [];

        while ($cursorTs < $endTs) {
            $best = null;
            $bestScore = -INF;

            foreach ($rows as $row) {
                $tid = $row['target']->getId();
                if (in_array($tid, $used, true)) {
                    continue;
                }
                $wsTs = (int) $row['night']['windowStartTs'];
                $weTs = (int) $row['night']['windowEndTs'];
                $effStart = max($cursorTs, $wsTs);
                if ($effStart >= $weTs) {
                    continue;
                }
                $blockMin = ($weTs - $effStart) / 60;
                $effectiveMin = $this->computeEffective($blockMin, $slewMin, $afTimeMin, $afIntMin, $flipMin);
                if ($effectiveMin < $minShootMin) {
                    continue;
                }
                if ($row['score'] > $bestScore) {
                    $bestScore = $row['score'];
                    $best = $row;
                }
            }

            if ($best === null) {
                break;
            }

            $tid      = $best['target']->getId();
            $used[]   = $tid;
            $wsTs     = (int) $best['night']['windowStartTs'];
            $weTs     = (int) $best['night']['windowEndTs'];
            $startTs  = max($cursorTs, $wsTs);
            $blockMin = ($weTs - $startTs) / 60;
            $shootSec = (int) ($this->computeEffective($blockMin, $slewMin, $afTimeMin, $afIntMin, $flipMin) * 60);

            $schedule[] = [
                'target'   => $best['target'],
                'framing'  => $best['framing'],
                'startTs'  => $startTs,
                'endTs'    => $weTs,
                'shootSec' => $shootSec,
            ];

            $cursorTs = $weTs;
        }

        if (empty($schedule)) {
            return new Response('No targets schedulable for this night/setup', 200);
        }

        // ── Build NINA sequence ──
        $date = $baseDate->format('Y-m-d');
        $seqName = sprintf('AstroPsy — %s — %s', $setup->getName(), $date);
        $json = $builder->buildFromSchedule($schedule, $setup, $seqName, date_default_timezone_get());

        $slug = preg_replace('/[^a-z0-9]+/i', '-', $setup->getName() ?? 'setup');
        $filename = sprintf('NINA-%s-%s.sequence.json', $slug, str_replace('-', '', $date));

        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', (string) strlen($json));

        return $response;
    }

    /** Port PHP de computeEffective() JS */
    private function computeEffective(
        float $windowMin,
        int $slewMin,
        int $afTimeMin,
        int $afIntMin,
        int $flipMin
    ): float {
        $initialOverhead = $slewMin + $afTimeMin;
        $shootMin  = max(0.0, $windowMin - $initialOverhead);
        $afCount   = $afIntMin > 0 ? floor($shootMin / $afIntMin) : 0;
        $overhead  = $initialOverhead + $afCount * $afTimeMin;
        return max(0.0, $windowMin - $overhead);
    }

    #[Route('/target/{id}/wishlist', name: 'target_wishlist_toggle', methods: ['POST'])]
    public function toggleWishlist(int $id, EntityManagerInterface $em): JsonResponse
    {
        $target = $em->getRepository(Target::class)->find($id);
        if (!$target) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }
        $target->setWishlist(!$target->isWishlist());
        $em->flush();

        return new JsonResponse(['wishlist' => $target->isWishlist()]);
    }
}
