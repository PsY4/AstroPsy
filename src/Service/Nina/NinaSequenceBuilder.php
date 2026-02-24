<?php

namespace App\Service\Nina;

use App\Entity\Setup;

/**
 * Builds a NINA 3.x Advanced Sequencer JSON from a night schedule.
 * Structure mirrors double.json (user's working reference).
 *
 * Input schedule format (array of blocks):
 *   [
 *     'target'    => Target entity,
 *     'framing'   => ['rotationAngle' => float, 'ra' => float, 'dec' => float] | null,
 *     'startTs'   => int  (unix timestamp),
 *     'endTs'     => int  (unix timestamp),
 *     'shootSec'  => int  (effective imaging seconds, overhead excluded),
 *   ]
 */
class NinaSequenceBuilder
{
    private int $nextId = 1;
    /** $id of the shared TimeProvider singleton (null = not yet emitted). */
    private ?string $sharedTimeProviderId = null;
    /** Timezone used for WaitForTime / TimeCondition H/M/S (NINA reads local machine time). */
    private string $timezone = 'UTC';

    public function buildFromSchedule(array $schedule, Setup $setup, string $sequenceName = 'AstroPsy Night Plan', string $timezone = ''): string
    {
        $this->nextId = 1;
        $this->sharedTimeProviderId = null;
        $this->timezone = $timezone ?: date_default_timezone_get();

        $targetBlocks = array_map(fn($block) => $this->buildTargetBlock($block, $setup), $schedule);

        $root = [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Container.SequenceRootContainer, NINA.Sequencer',
            'Strategy'   => $this->seqStrategy(),
            'Name'       => $sequenceName,
            'Conditions' => $this->emptyConditions(),
            'IsExpanded' => true,
            'Items'      => $this->items([
                $this->buildStartArea($setup),
                $this->buildTargetArea($targetBlocks),
                $this->buildEndArea(),
            ]),
            'Triggers'      => $this->emptyTriggers(),
            'Parent'        => null,
            'ErrorBehavior' => 0,
            'Attempts'      => 1,
        ];

        // Post-process 1: add Parent $ref to all sequencer items (required by NINA for proper init)
        $root = $this->addParentRefs($root);
        // Post-process 2: renumber $id/$ref values sequentially in document order (like NINA native format)
        $root = $this->renumberIds($root);

        return json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // ── Post-processing ──────────────────────────────────────────────────────

    /**
     * Add Parent: {$ref: N} to all sequencer items inside Items/Conditions/Triggers collections.
     * $itemsParent is the $id of the container that owns the collection containing $node.
     */
    private function addParentRefs(mixed $node, ?string $itemsParent = null): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $myId = $node['$id'] ?? null;
        $type = $node['$type'] ?? '';

        // Set Parent on sequencer nodes that are inside a container's collection
        if ($itemsParent !== null && $myId !== null && $this->isSequenceNode($type)) {
            $node['Parent'] = ['$ref' => $itemsParent];
        }

        // Process Items, Conditions, Triggers: their $values items get Parent = myId
        foreach (['Items', 'Conditions', 'Triggers'] as $collKey) {
            if (isset($node[$collKey]['$values']) && is_array($node[$collKey]['$values'])) {
                $node[$collKey]['$values'] = array_map(
                    fn($item) => $this->addParentRefs($item, $myId),
                    $node[$collKey]['$values']
                );
            }
        }

        // Recurse into all other sub-arrays (TriggerRunner, Data, Target, Filter, etc.)
        // Pass null so these sub-objects don't get spurious Parent set, but their own
        // Items/Conditions/Triggers are still processed recursively.
        foreach ($node as $key => $value) {
            if (in_array($key, ['Items', 'Conditions', 'Triggers', 'Parent'], true)) {
                continue;
            }
            if (is_array($value)) {
                $node[$key] = $this->addParentRefs($value, null);
            }
        }

        return $node;
    }

    /** Returns true for NINA sequencer nodes that need a Parent field. */
    private function isSequenceNode(string $type): bool
    {
        return str_contains($type, 'NINA.Sequencer.')
            && !str_contains($type, 'ObservableCollection')
            && !str_contains($type, 'AsyncObservableCollection')
            && !str_contains($type, 'WaitLoopData')
            && !str_contains($type, 'Strategy')
            && !str_contains($type, 'DateTimeProvider')
            && !str_contains($type, 'ExposureInfo');
    }

    /**
     * Renumber all $id/$ref values so they are sequential (1, 2, 3…) in JSON document order.
     * This matches the format NINA generates natively.
     */
    private function renumberIds(array $root): array
    {
        $map     = [];
        $counter = 0;
        $this->collectIdOrder($root, $map, $counter);
        return $this->applyIdMap($root, $map);
    }

    private function collectIdOrder(mixed $node, array &$map, int &$counter): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['$id'])) {
            $map[$node['$id']] = (string) ++$counter;
        }
        foreach ($node as $v) {
            $this->collectIdOrder($v, $map, $counter);
        }
    }

    private function applyIdMap(mixed $node, array $map): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $result = [];
        foreach ($node as $k => $v) {
            if (($k === '$id' || $k === '$ref') && is_string($v) && isset($map[$v])) {
                $result[$k] = $map[$v];
            } else {
                $result[$k] = $this->applyIdMap($v, $map);
            }
        }
        return $result;
    }

    // ── Areas ────────────────────────────────────────────────────────────────

    private function buildStartArea(Setup $setup): array
    {
        $coolingTemp = $setup->getCameraCoolingTemp() ?? -10.0;

        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Container.StartAreaContainer, NINA.Sequencer',
            'Strategy'   => $this->seqStrategy(),
            'Name'       => 'Start',
            'Conditions' => $this->emptyConditions(),
            'IsExpanded' => true,
            'Items'      => $this->items([
                $this->parallelContainer('🌙 Démarrage de nuit', [
                    $this->item('NINA.Sequencer.SequenceItem.Camera.CoolCamera, NINA.Sequencer', [
                        'Temperature' => (float) $coolingTemp,
                        'Duration'    => 0.0,
                    ]),
                    $this->item('NINA.Sequencer.SequenceItem.Telescope.UnparkScope, NINA.Sequencer'),
                ]),
            ]),
            'Triggers'      => $this->emptyTriggers(),
            'Parent'        => null,
            'ErrorBehavior' => 0,
            'Attempts'      => 1,
        ];
    }

    private function buildTargetArea(array $targetBlocks): array
    {
        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Container.TargetAreaContainer, NINA.Sequencer',
            'Strategy'   => $this->seqStrategy(),
            'Name'       => 'Targets',
            'Conditions' => $this->emptyConditions(),
            'IsExpanded' => true,
            'Items'      => $this->items($targetBlocks),
            'Triggers'      => $this->emptyTriggers(),
            'Parent'        => null,
            'ErrorBehavior' => 0,
            'Attempts'      => 1,
        ];
    }

    private function buildEndArea(): array
    {
        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Container.EndAreaContainer, NINA.Sequencer',
            'Strategy'   => $this->seqStrategy(),
            'Name'       => 'End',
            'Conditions' => $this->emptyConditions(),
            'IsExpanded' => true,
            'Items'      => $this->items([
                $this->seqContainer('🌅 Fin de nuit', [], [
                    $this->parallelContainer('🏁 Fin de séquence', [
                        $this->item('NINA.Sequencer.SequenceItem.Camera.WarmCamera, NINA.Sequencer', ['Duration' => 0.0]),
                        $this->item('NINA.Sequencer.SequenceItem.Telescope.ParkScope, NINA.Sequencer'),
                    ]),
                ]),
            ]),
            'Triggers'      => $this->emptyTriggers(),
            'Parent'        => null,
            'ErrorBehavior' => 0,
            'Attempts'      => 1,
        ];
    }

    // ── Target block ─────────────────────────────────────────────────────────

    private function buildTargetBlock(array $block, Setup $setup): array
    {
        $target   = $block['target'];
        $framing  = $block['framing'] ?? null;
        $shootSec = (int) ($block['shootSec'] ?? 0);
        $startTs  = (int) ($block['startTs'] ?? 0);
        $endTs    = (int) ($block['endTs']   ?? 0);

        $ra  = $framing ? (float) $framing['ra']  : (float) $target->getRa();
        $dec = $framing ? (float) $framing['dec'] : (float) $target->getDec();
        $rot = $framing ? (float) ($framing['rotationAngle'] ?? 0.0) : 0.0;

        [$raH, $raM, $raS]             = $this->decimalToHms($ra);
        [$negDec, $decD, $decM, $decS] = $this->decimalToDms($dec);

        // Chaque usage de coordonnées doit avoir son propre $id unique (Json.NET $ref tracking)
        $mkCoords = function () use ($raH, $raM, $raS, $negDec, $decD, $decM, $decS): array {
            return [
                '$id'        => $this->id(),
                '$type'      => 'NINA.Astrometry.InputCoordinates, NINA.Astrometry',
                'RAHours'    => $raH,
                'RAMinutes'  => $raM,
                'RASeconds'  => round($raS, 3),
                'NegativeDec' => $negDec,
                'DecDegrees' => $decD,
                'DecMinutes' => $decM,
                'DecSeconds' => round($decS, 3),
            ];
        };

        $dsoId = $this->id();

        $isMono  = ($setup->getImagingType() === 'MONO');
        $filters = $setup->getFiltersConfig() ?? [];

        // Restrict to the filters selected for this target (null = use all)
        $filtersSelected = $framing['filtersSelected'] ?? null;
        if ($filtersSelected !== null && count($filtersSelected) > 0) {
            $filters = array_values(array_filter($filters, fn($f) => in_array((int)($f['position'] ?? -1), $filtersSelected, true)));
        }

        $gain    = $setup->getCameraGain()    ?? -1;
        $offset  = $setup->getCameraOffset()  ?? -1;
        $binning = $setup->getCameraBinning() ?? 1;
        $ditherEvery = $setup->getDitherEvery() ?? 1;

        // WaitForTime start — converted to local timezone (NINA reads H/M/S in machine local time)
        $tz      = new \DateTimeZone($this->timezone);
        $startDt = (new \DateTimeImmutable('@' . ($startTs ?: time())))->setTimezone($tz);
        $endDt   = (new \DateTimeImmutable('@' . ($endTs ?: time() + 3600)))->setTimezone($tz);

        return [
            '$id'    => (string) $dsoId,
            '$type'  => 'NINA.Sequencer.Container.DeepSkyObjectContainer, NINA.Sequencer',
            'Target' => [
                '$id'   => $this->id(),
                '$type' => 'NINA.Astrometry.InputTarget, NINA.Astrometry',
                'Expanded'    => true,
                'TargetName'  => $target->getName(),
                'PositionAngle' => $rot,
                'InputCoordinates' => $mkCoords(),
            ],
            'ExposureInfoListExpanded' => false,
            'ExposureInfoList' => [
                '$id'    => $this->id(),
                '$type'  => 'NINA.Core.Utility.AsyncObservableCollection`1[[NINA.Sequencer.Utility.ExposureInfo, NINA.Sequencer]], NINA.Core',
                '$values' => [],
            ],
            'Strategy'   => $this->seqStrategy(),
            'Name'       => '🔭 ' . $target->getName(),
            'Conditions' => $this->emptyConditions(),
            'IsExpanded' => true,
            'Items'      => $this->items([
                // 1. Vérification équipement
                $this->seqContainer('⚙️ Vérification équipement', [], [
                    $this->item('NINA.Sequencer.SequenceItem.Telescope.UnparkScope, NINA.Sequencer'),
                    $this->item('NINA.Sequencer.SequenceItem.Telescope.SetTracking, NINA.Sequencer', ['TrackingMode' => 0]),
                    $this->item('NINA.Sequencer.SequenceItem.Camera.CoolCamera, NINA.Sequencer', [
                        'Temperature' => (float) ($setup->getCameraCoolingTemp() ?? -10.0),
                        'Duration'    => 0.0,
                    ]),
                ]),
                // 2. Wait for target start time
                $this->item('NINA.Sequencer.SequenceItem.Utility.WaitForTime, NINA.Sequencer', [
                    'Hours'           => (int) $startDt->format('G'),
                    'Minutes'         => (int) $startDt->format('i'),
                    'MinutesOffset'   => 0,
                    'Seconds'         => (int) $startDt->format('s'),
                    'SelectedProvider' => $this->timeProviderRef(),
                ]),
                // 3. Wait until above horizon
                $this->item('NINA.Sequencer.SequenceItem.Utility.WaitUntilAboveHorizon, NINA.Sequencer', [
                    'HasDsoParent' => true,
                    'Data'         => $this->waitLoopData($mkCoords()),
                ]),
                // 4. Préparation cible
                $this->seqContainer('🎯 Préparation cible', [], array_merge(
                    $isMono && !empty($filters) ? [$this->buildSwitchFilter($filters[0])] : [],
                    [
                        $this->item('NINA.Sequencer.SequenceItem.Platesolving.CenterAndRotate, NINA.Sequencer', [
                                'PositionAngle' => $rot,
                                'Inherited'     => true,
                                'Coordinates'   => $this->zeroCoords(),
                            ]),
                        $this->item('NINA.Sequencer.SequenceItem.Guider.StartGuiding, NINA.Sequencer', ['ForceCalibration' => false]),
                        $this->item('NINA.Sequencer.SequenceItem.Autofocus.RunAutofocus, NINA.Sequencer'),
                    ]
                )),
                        // 5. IMAGING block
                $this->buildImagingBlock(
                    $setup, $isMono, $filters, $shootSec,
                    $gain, $offset, $binning, $ditherEvery,
                    $endDt, $mkCoords()
                ),
            ]),
            'Triggers' => $this->items([
                $this->buildMeridianFlipTrigger(),
                $this->buildCenterAfterDriftTrigger($mkCoords()),
                $this->buildRestoreGuidingTrigger(),
            ], 'triggers'),
            'Parent'        => null,
            'ErrorBehavior' => 0,
            'Attempts'      => 1,
        ];
    }

    // ── Imaging block ─────────────────────────────────────────────────────────

    private function buildImagingBlock(
        Setup $setup,
        bool $isMono,
        array $filters,
        int $shootSec,
        int $gain,
        int $offset,
        int $binning,
        int $ditherEvery,
        \DateTimeImmutable $endDt,
        array $coords
    ): array {
        // Conditions: TimeCondition (end time) + AboveHorizonCondition
        $conditions = $this->conditionsCollection([
            array_merge($this->baseCondition('NINA.Sequencer.Conditions.TimeCondition, NINA.Sequencer'), [
                'Hours'           => (int) $endDt->format('G'),
                'Minutes'         => (int) $endDt->format('i'),
                'MinutesOffset'   => 0,
                'Seconds'         => (int) $endDt->format('s'),
                'SelectedProvider' => $this->timeProviderRef(),
            ]),
            array_merge($this->baseCondition('NINA.Sequencer.Conditions.AboveHorizonCondition, NINA.Sequencer'), [
                'HasDsoParent' => true,
                'Data'         => $this->waitLoopData($coords),
            ]),
        ]);

        // Imaging items
        $imagingItems = $isMono
            ? $this->buildMonoItems($filters, $shootSec, $gain, $offset, $binning, $ditherEvery)
            : $this->buildOscItems($shootSec, $gain, $offset, $binning, $ditherEvery);

        // Imaging triggers
        $imagingTriggers = $this->items([
            $this->buildAfterFilterChangeTrigger(),
            $this->buildHfrTrigger(),
            $this->buildDitherTrigger($ditherEvery),
        ], 'triggers');

        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Container.SequentialContainer, NINA.Sequencer',
            'Strategy'   => $this->seqStrategy(),
            'Name'       => '📸 Acquisition',
            'Conditions' => $conditions,
            'IsExpanded' => true,
            'Items'      => $this->items($imagingItems),
            'Triggers'   => $imagingTriggers,
            'Parent'     => null,
            'ErrorBehavior' => 0,
            'Attempts'   => 1,
        ];
    }

    // ── MONO imaging ──────────────────────────────────────────────────────────

    private function buildMonoItems(array $filters, int $shootSec, int $gain, int $offset, int $binning, int $ditherEvery): array
    {
        if (empty($filters)) {
            return [];
        }

        $nbFilters = count($filters);
        $items     = [];

        foreach ($filters as $f) {
            $expTime   = (int) ($f['exposureTime'] ?? 300);
            $perFilter = (int) floor($shootSec / $nbFilters);
            $nbPoses   = max(1, (int) floor($perFilter / max(1, $expTime)));
            $items[]   = $this->buildSmartExposure($f, $nbPoses, $gain, $offset, $binning, $ditherEvery);
        }

        return $items;
    }

    private function buildSmartExposure(array $filter, int $nbPoses, int $gain, int $offset, int $binning, int $ditherEvery): array
    {
        $filterName = $filter['ninaName'] ?? '';
        $expTime    = (float) ($filter['exposureTime'] ?? 300);

        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.SequenceItem.Imaging.SmartExposure, NINA.Sequencer',
            'Strategy'   => $this->seqStrategy(),
            'Name'       => 'Smart Exposure',
            'Conditions' => $this->conditionsCollection([
                array_merge(
                    $this->baseCondition('NINA.Sequencer.Conditions.LoopCondition, NINA.Sequencer'),
                    ['CompletedIterations' => 0, 'Iterations' => $nbPoses]
                ),
            ]),
            'IsExpanded' => false,
            'Items'      => $this->items([
                $this->buildSwitchFilter($filter),
                $this->item('NINA.Sequencer.SequenceItem.Imaging.TakeExposure, NINA.Sequencer', [
                    'ExposureTime'  => $expTime,
                    'Gain'          => $gain,
                    'Offset'        => $offset,
                    'Binning'       => $this->binning($binning),
                    'ImageType'     => 'LIGHT',
                    'ExposureCount' => 0,
                ]),
            ]),
            'Triggers' => $this->items([
                $this->buildDitherTrigger(0),
            ], 'triggers'),
            'Parent'        => null,
            'ErrorBehavior' => 0,
            'Attempts'      => 1,
        ];
    }

    private function buildSwitchFilter(array $filter): array
    {
        $pos    = (int)   ($filter['position']     ?? 0) - 1; // NINA is 0-indexed internally
        $foff   = (int)   ($filter['focusOffset']  ?? 0);
        $name   = (string)($filter['ninaName']     ?? '');

        return $this->item('NINA.Sequencer.SequenceItem.FilterWheel.SwitchFilter, NINA.Sequencer', [
            'Filter' => [
                '$id'   => $this->id(),
                '$type' => 'NINA.Core.Model.Equipment.FilterInfo, NINA.Core',
                '_name'               => $name,
                '_focusOffset'        => $foff,
                '_position'           => $pos,
                '_autoFocusExposureTime' => -1.0,
                '_autoFocusFilter'       => false,
                'FlatWizardFilterSettings' => [
                    '$id'   => $this->id(),
                    '$type' => 'NINA.Core.Model.Equipment.FlatWizardFilterSettings, NINA.Core',
                    'FlatWizardMode'                  => 0,
                    'HistogramMeanTarget'             => 0.5,
                    'HistogramTolerance'              => 0.1,
                    'MaxFlatExposureTime'             => 30.0,
                    'MinFlatExposureTime'             => 0.01,
                    'MaxAbsoluteFlatDeviceBrightness' => 255,
                    'MinAbsoluteFlatDeviceBrightness' => 0,
                    'Gain'    => -1,
                    'Offset'  => -1,
                    'Binning' => $this->binning(1),
                ],
                '_autoFocusBinning' => $this->binning(1),
                '_autoFocusGain'    => -1,
                '_autoFocusOffset'  => -1,
            ],
        ]);
    }

    // ── OSC imaging ───────────────────────────────────────────────────────────

    private function buildOscItems(int $shootSec, int $gain, int $offset, int $binning, int $ditherEvery): array
    {
        $expTime = 120.0;
        $nbPoses = max(1, (int) floor($shootSec / max(1, (int)$expTime)));

        return [
            $this->item('NINA.Sequencer.SequenceItem.Imaging.TakeExposure, NINA.Sequencer', [
                'ExposureTime'  => $expTime,
                'Gain'          => $gain,
                'Offset'        => $offset,
                'Binning'       => $this->binning($binning),
                'ImageType'     => 'LIGHT',
                'ExposureCount' => $nbPoses,
            ]),
        ];
    }

    // ── Triggers ──────────────────────────────────────────────────────────────

    private function buildMeridianFlipTrigger(): array
    {
        return array_merge(
            $this->baseTrigger('NINA.Sequencer.Trigger.MeridianFlip.MeridianFlipTrigger, NINA.Sequencer'),
            ['TriggerRunner' => $this->emptyTriggerRunner()]
        );
    }

    private function buildCenterAfterDriftTrigger(array $coords): array
    {
        // Key order: data fields, then Parent, then TriggerRunner (matches NINA native format)
        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Trigger.Platesolving.CenterAfterDriftTrigger, NINA.Sequencer',
            'Coordinates'        => $coords,
            'DistanceArcMinutes' => 3.0,
            'AfterExposures'     => 1,
            'Parent'             => null,
            'TriggerRunner'      => $this->emptyTriggerRunner(),
        ];
    }

    private function buildRestoreGuidingTrigger(): array
    {
        $runner = $this->emptyTriggerRunner();
        $runner['Items'] = $this->items([
            $this->item('NINA.Sequencer.SequenceItem.Guider.StartGuiding, NINA.Sequencer', ['ForceCalibration' => false]),
        ]);

        return array_merge(
            $this->baseTrigger('NINA.Sequencer.Trigger.Guider.RestoreGuiding, NINA.Sequencer'),
            ['TriggerRunner' => $runner]
        );
    }

    private function buildAfterFilterChangeTrigger(): array
    {
        $runner = $this->emptyTriggerRunner();
        $runner['Items'] = $this->items([
            $this->item('NINA.Sequencer.SequenceItem.Autofocus.RunAutofocus, NINA.Sequencer'),
        ]);

        return array_merge(
            $this->baseTrigger('NINA.Sequencer.Trigger.Autofocus.AutofocusAfterFilterChange, NINA.Sequencer'),
            ['TriggerRunner' => $runner]
        );
    }

    private function buildHfrTrigger(): array
    {
        $runner = $this->emptyTriggerRunner();
        $runner['Items'] = $this->items([
            $this->item('NINA.Sequencer.SequenceItem.Autofocus.RunAutofocus, NINA.Sequencer'),
        ]);

        return [
            '$id'   => $this->id(),
            '$type' => 'NINA.Sequencer.Trigger.Autofocus.AutofocusAfterHFRIncreaseTrigger, NINA.Sequencer',
            'Amount'    => 10.0,
            'SampleSize' => 5,
            'Parent'    => null,
            'TriggerRunner' => $runner,
        ];
    }

    private function buildDitherTrigger(int $afterExposures): array
    {
        $runner = $this->emptyTriggerRunner();
        $runner['Items'] = $this->items([
            $this->item('NINA.Sequencer.SequenceItem.Guider.Dither, NINA.Sequencer'),
        ]);

        return [
            '$id'   => $this->id(),
            '$type' => 'NINA.Sequencer.Trigger.Guider.DitherAfterExposures, NINA.Sequencer',
            'AfterExposures' => $afterExposures,
            'Parent'        => null,
            'TriggerRunner' => $runner,
        ];
    }

    // ── Primitive helpers ─────────────────────────────────────────────────────

    private function id(): string
    {
        return (string) $this->nextId++;
    }

    /**
     * Returns the shared TimeProvider singleton.
     * First call: emits the full object with a new $id.
     * Subsequent calls: returns a $ref to the first object.
     * This mirrors NINA's native format where a single TimeProvider instance
     * is shared across all WaitForTime items and TimeConditions.
     */
    private function timeProviderRef(): array
    {
        if ($this->sharedTimeProviderId === null) {
            $this->sharedTimeProviderId = $this->id();
            return [
                '$id'   => $this->sharedTimeProviderId,
                '$type' => 'NINA.Sequencer.Utility.DateTimeProvider.TimeProvider, NINA.Sequencer',
            ];
        }
        return ['$ref' => $this->sharedTimeProviderId];
    }

    private function seqStrategy(): array
    {
        return ['$type' => 'NINA.Sequencer.Container.ExecutionStrategy.SequentialStrategy, NINA.Sequencer'];
    }

    private function emptyConditions(): array
    {
        return [
            '$id'     => $this->id(),
            '$type'   => 'System.Collections.ObjectModel.ObservableCollection`1[[NINA.Sequencer.Conditions.ISequenceCondition, NINA.Sequencer]], System.ObjectModel',
            '$values' => [],
        ];
    }

    private function conditionsCollection(array $conds): array
    {
        return [
            '$id'     => $this->id(),
            '$type'   => 'System.Collections.ObjectModel.ObservableCollection`1[[NINA.Sequencer.Conditions.ISequenceCondition, NINA.Sequencer]], System.ObjectModel',
            '$values' => $conds,
        ];
    }

    private function emptyTriggers(): array
    {
        return [
            '$id'     => $this->id(),
            '$type'   => 'System.Collections.ObjectModel.ObservableCollection`1[[NINA.Sequencer.Trigger.ISequenceTrigger, NINA.Sequencer]], System.ObjectModel',
            '$values' => [],
        ];
    }

    /** @param string $kind 'items'|'triggers' */
    private function items(array $values, string $kind = 'items'): array
    {
        $type = $kind === 'triggers'
            ? 'System.Collections.ObjectModel.ObservableCollection`1[[NINA.Sequencer.Trigger.ISequenceTrigger, NINA.Sequencer]], System.ObjectModel'
            : 'System.Collections.ObjectModel.ObservableCollection`1[[NINA.Sequencer.SequenceItem.ISequenceItem, NINA.Sequencer]], System.ObjectModel';

        return [
            '$id'     => $this->id(),
            '$type'   => $type,
            '$values' => $values,
        ];
    }

    private function baseCondition(string $type): array
    {
        // No 'Parent' here — addParentRefs will append it at the end,
        // which matches the NINA native format: data fields, then Parent.
        return [
            '$id'   => $this->id(),
            '$type' => $type,
        ];
    }

    private function baseItem(string $type): array
    {
        return [
            '$id'           => $this->id(),
            '$type'         => $type,
            'Parent'        => null,
            'ErrorBehavior' => 0,
            'Attempts'      => 1,
        ];
    }

    /**
     * Build a simple sequence item.
     * Key order: $id, $type, [extra data], Parent, ErrorBehavior, Attempts
     * This matches the NINA native format where data fields precede Parent.
     */
    private function item(string $type, array $extra = []): array
    {
        return array_merge(
            ['$id' => $this->id(), '$type' => $type],
            $extra,
            ['Parent' => null, 'ErrorBehavior' => 0, 'Attempts' => 1]
        );
    }

    private function baseTrigger(string $type): array
    {
        return [
            '$id'    => $this->id(),
            '$type'  => $type,
            'Parent' => null,
        ];
    }

    private function emptyTriggerRunner(): array
    {
        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Container.SequentialContainer, NINA.Sequencer',
            'Strategy'   => $this->seqStrategy(),
            'Name'       => null,
            'Conditions' => $this->emptyConditions(),
            'IsExpanded' => true,
            'Items'      => $this->items([]),
            'Triggers'   => $this->emptyTriggers(),
            'Parent'     => null,
            'ErrorBehavior' => 0,
            'Attempts'   => 1,
        ];
    }

    private function seqContainer(string $name, array $triggers, array $itemsList, ?array $conditions = null): array
    {
        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Container.SequentialContainer, NINA.Sequencer',
            'Strategy'   => $this->seqStrategy(),
            'Name'       => $name,
            'Conditions' => $conditions ?? $this->emptyConditions(),
            'IsExpanded' => true,
            'Items'      => $this->items($itemsList),
            'Triggers'   => empty($triggers) ? $this->emptyTriggers() : $this->items($triggers, 'triggers'),
            'Parent'     => null,
            'ErrorBehavior' => 0,
            'Attempts'   => 1,
        ];
    }

    private function parallelContainer(string $name, array $itemsList): array
    {
        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.Container.ParallelContainer, NINA.Sequencer',
            'Strategy'   => ['$type' => 'NINA.Sequencer.Container.ExecutionStrategy.ParallelStrategy, NINA.Sequencer'],
            'Name'       => $name,
            'Conditions' => $this->emptyConditions(),
            'IsExpanded' => true,
            'Items'      => $this->items($itemsList),
            'Triggers'   => $this->emptyTriggers(),
            'Parent'     => null,
            'ErrorBehavior' => 0,
            'Attempts'   => 1,
        ];
    }

    private function waitLoopData(array $coords): array
    {
        return [
            '$id'    => $this->id(),
            '$type'  => 'NINA.Sequencer.SequenceItem.Utility.WaitLoopData, NINA.Sequencer',
            'Coordinates' => $coords,
            'Offset'      => 0.0,
            'Comparator'  => 3,
        ];
    }

    private function binning(int $b): array
    {
        return [
            '$id'   => $this->id(),
            '$type' => 'NINA.Core.Model.Equipment.BinningMode, NINA.Core',
            'X'     => $b,
            'Y'     => $b,
        ];
    }

    private function zeroCoords(): array
    {
        return [
            '$id'        => $this->id(),
            '$type'      => 'NINA.Astrometry.InputCoordinates, NINA.Astrometry',
            'RAHours'    => 0, 'RAMinutes' => 0, 'RASeconds' => 0.0,
            'NegativeDec' => false,
            'DecDegrees' => 0, 'DecMinutes' => 0, 'DecSeconds' => 0.0,
        ];
    }

    // ── Coordinate conversions ────────────────────────────────────────────────

    private function decimalToHms(float $deg): array
    {
        $h   = (int) floor($deg);
        $rem = ($deg - $h) * 60;
        $m   = (int) floor($rem);
        $s   = ($rem - $m) * 60;
        return [$h, $m, $s];
    }

    private function decimalToDms(float $deg): array
    {
        $neg = $deg < 0;
        $abs = abs($deg);
        $d   = (int) floor($abs);
        $rem = ($abs - $d) * 60;
        $m   = (int) floor($rem);
        $s   = ($rem - $m) * 60;
        return [$neg, $d, $m, $s];
    }
}
