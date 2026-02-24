<?php
namespace App\Controller;

use App\Service\AlpacaClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AlpacaSetupController extends AbstractController
{
    public function __construct(private AlpacaClient $alpaca) {}

    #[Route('/alpaca/setup', name: 'alpaca_setup_index')]
    public function index(): Response
    {
        $sm     = $this->alpaca->getSafetyMonitorConfig(0);
        $switch = $this->alpaca->getSwitchConfig(0);

        return $this->render('alpaca/index.html.twig', [
            'sm'     => $sm,
            'switch' => $switch,
        ]);
    }

    #[Route('/alpaca/setup/safetymonitor/{deviceNumber}', name: 'alpaca_setup_safetymonitor', methods: ['GET'])]
    public function safetyMonitorSetup(int $deviceNumber): Response
    {
        $data = $this->alpaca->getSafetyMonitorConfig($deviceNumber);

        return $this->render('alpaca/safetymonitor_setup.html.twig', [
            'deviceNumber' => $deviceNumber,
            'data'         => $data,
        ]);
    }

    #[Route('/alpaca/setup/safetymonitor/{deviceNumber}', name: 'alpaca_setup_safetymonitor_save', methods: ['POST'])]
    public function safetyMonitorSave(int $deviceNumber, Request $request): Response
    {
        $allConditions = ['Clear', 'Wisps of clouds', 'Mostly Cloudy', 'Overcast', 'Rain', 'Snow'];

        $unsafeConditions = [];
        foreach ($allConditions as $cond) {
            if ($request->request->get('unsafe_' . $cond)) {
                $unsafeConditions[] = $cond;
            }
        }

        $payload = [
            'device_name'       => $request->request->get('device_name', ''),
            'location'          => $request->request->get('location', ''),
            'latitude'          => (float) $request->request->get('latitude', 0),
            'longitude'         => (float) $request->request->get('longitude', 0),
            'elevation'         => (int)   $request->request->get('elevation', 0),
            'timezone'          => $request->request->get('timezone', ''),
            'forecast_url'      => $request->request->get('forecast_url', ''),
            'unsafe_conditions' => $unsafeConditions,
        ];

        $this->alpaca->saveSafetyMonitorConfig($deviceNumber, $payload);

        return $this->redirectToRoute('alpaca_setup_safetymonitor', ['deviceNumber' => $deviceNumber]);
    }

    #[Route('/alpaca/setup/switch/{deviceNumber}', name: 'alpaca_setup_switch', methods: ['GET'])]
    public function switchSetup(int $deviceNumber): Response
    {
        $data = $this->alpaca->getSwitchConfig($deviceNumber);

        return $this->render('alpaca/switch_setup.html.twig', [
            'deviceNumber' => $deviceNumber,
            'data'         => $data,
        ]);
    }

    #[Route('/alpaca/setup/switch/{deviceNumber}', name: 'alpaca_setup_switch_save', methods: ['POST'])]
    public function switchSave(int $deviceNumber, Request $request): Response
    {
        $action = $request->request->get('action', 'save');

        if ($action === 'add') {
            $isBoolean = $request->request->get('new_type', 'switch') === 'switch';
            $payload = [
                'action'      => 'add',
                'name'        => $request->request->get('new_name', 'New Item'),
                'description' => $request->request->get('new_desc', ''),
                'is_boolean'  => $isBoolean,
                'min_value'   => (float) $request->request->get('new_min', 0),
                'max_value'   => (float) $request->request->get('new_max', 100),
                'step'        => (float) $request->request->get('new_step', 0.1),
            ];
            $this->alpaca->saveSwitchConfig($deviceNumber, $payload);

        } elseif ($action === 'delete') {
            $ids = array_map('intval', (array) $request->request->all('delete_ids'));
            $this->alpaca->saveSwitchConfig($deviceNumber, ['action' => 'delete', 'ids' => $ids]);

        } else {
            // save all items
            $rawItems = $request->request->all();
            $itemUpdates = [];

            // Collect all item ids from the form (name_N fields)
            $ids = [];
            foreach ($rawItems as $key => $val) {
                if (preg_match('/^name_(\d+)$/', $key, $m)) {
                    $ids[] = (int) $m[1];
                }
            }

            $toDelete = [];
            foreach ($ids as $id) {
                if ($request->request->get("delete_{$id}")) {
                    $toDelete[] = $id;
                    continue;
                }
                $itemUpdates[] = [
                    'id'          => $id,
                    'name'        => $request->request->get("name_{$id}", ''),
                    'description' => $request->request->get("desc_{$id}", ''),
                    'value'       => $request->request->get("value_{$id}") !== null
                                        ? (float) $request->request->get("value_{$id}")
                                        : 0.0,
                    'min_value'   => (float) $request->request->get("min_{$id}", 0),
                    'max_value'   => (float) $request->request->get("max_{$id}", 1),
                    'step'        => (float) $request->request->get("step_{$id}", 1),
                ];
            }

            if (!empty($toDelete)) {
                rsort($toDelete);
                $this->alpaca->saveSwitchConfig($deviceNumber, ['action' => 'delete', 'ids' => $toDelete]);
            }
            if (!empty($itemUpdates)) {
                $this->alpaca->saveSwitchConfig($deviceNumber, ['action' => 'save', 'items' => $itemUpdates]);
            }
        }

        return $this->redirectToRoute('alpaca_setup_switch', ['deviceNumber' => $deviceNumber]);
    }
}
