<?php

namespace App\Controller;

use App\Entity\Session;
use App\Enum\SessionFolder;
use App\Message\MigrateSessionsMessage;
use App\Service\AppConfig;
use App\Service\StoragePathResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class SettingsController extends AbstractController
{
    public function __construct(
        private readonly StoragePathResolver $resolver,
    ) {}

    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function index(AppConfig $config): Response
    {
        $roles = array_map(fn(SessionFolder $f) => $f->value, SessionFolder::cases());

        return $this->render('settings/index.html.twig', [
            'notifications'    => $config->getSection('notifications'),
            'filters'          => $config->getFilters(),
            'template_tree'    => $config->getSessionTemplate(),
            'roles'            => $roles,
        ]);
    }

    #[Route('/settings/section/{section}', name: 'settings_save_section', methods: ['POST'])]
    public function saveSection(string $section, Request $request, AppConfig $config, StoragePathResolver $resolver, TranslatorInterface $translator): Response
    {
        match ($section) {
            'notifications'    => $this->saveNotifications($request, $config),
            'filters'          => $this->saveFilters($request, $config),
            'session_template' => $this->saveSessionTemplate($request, $config, $resolver, $translator),
            default            => null,
        };

        $this->addFlash('success', $translator->trans('settings.saved'));
        return $this->redirectToRoute('settings');
    }

    #[Route('/settings/locale/{locale}', name: 'settings_locale', methods: ['POST'])]
    public function setLocale(string $locale, AppConfig $config, Request $request): Response
    {
        if (in_array($locale, ['fr', 'en'], true)) {
            $config->setLocale($locale);
        }
        return $this->redirect($request->headers->get('referer', '/'));
    }

    #[Route('/settings/theme/{theme}', name: 'settings_theme', methods: ['POST'])]
    public function setTheme(string $theme, AppConfig $config): JsonResponse
    {
        if (in_array($theme, ['dark', 'light'], true)) {
            $config->setTheme($theme);
        }
        return new JsonResponse(['theme' => $config->getTheme()]);
    }

    #[Route('/settings/openfolder/install.cmd', name: 'openfolder_download', methods: ['GET'])]
    public function openfolderDownload(): BinaryFileResponse
    {
        $path = $this->getParameter('kernel.project_dir') . '/openfolder/install.cmd';
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'install.cmd');

        return $response;
    }

    #[Route('/settings/template/dry-run', name: 'settings_template_dry_run', methods: ['POST'])]
    public function templateDryRun(AppConfig $config, EntityManagerInterface $em): JsonResponse
    {
        $template = $config->getSessionTemplate();
        $sessions = $em->getRepository(Session::class)->findAll();

        $newRoleMap = [];
        $this->buildRoleMapFromTree($template['tree'] ?? [], '', $newRoleMap);

        $moves   = 0;
        $skipped = 0;
        $details = [];

        foreach ($sessions as $session) {
            $absSessionPath = $this->resolver->toAbsolutePath($session->getPath());
            if (!$absSessionPath || !is_dir($absSessionPath)) {
                $skipped++;
                continue;
            }

            $sessionMoves = [];
            foreach (SessionFolder::cases() as $folder) {
                $newRelPath = $newRoleMap[$folder->value] ?? $folder->defaultRelativePath();
                $oldDir = $absSessionPath . '/' . $folder->defaultRelativePath();
                $newDir = $absSessionPath . '/' . $newRelPath;

                if ($oldDir === $newDir) {
                    continue;
                }

                $fileCount = 0;
                if (is_dir($oldDir)) {
                    $items = @scandir($oldDir);
                    if ($items) {
                        $fileCount = count(array_diff($items, ['.', '..']));
                    }
                }

                if ($fileCount > 0) {
                    $sessionMoves[] = [
                        'role'  => $folder->value,
                        'from'  => $folder->defaultRelativePath(),
                        'to'    => $newRelPath,
                        'files' => $fileCount,
                    ];
                    $moves += $fileCount;
                }
            }

            if (!empty($sessionMoves)) {
                $details[] = [
                    'session' => $session->getTarget()?->getName() . ' / ' . basename($absSessionPath),
                    'moves'   => $sessionMoves,
                ];
            }
        }

        return new JsonResponse([
            'total_sessions' => count($sessions),
            'affected'       => count($details),
            'skipped'        => $skipped,
            'total_moves'    => $moves,
            'details'        => $details,
        ]);
    }

    #[Route('/settings/template/migrate', name: 'settings_template_migrate', methods: ['POST'])]
    public function templateMigrate(AppConfig $config, MessageBusInterface $bus): JsonResponse
    {
        $template = $config->getSessionTemplate();
        $bus->dispatch(new MigrateSessionsMessage($template));

        return new JsonResponse(['dispatched' => true]);
    }

    // --- Sections ---

    private function saveFilters(Request $request, AppConfig $config): void
    {
        $labels  = $request->request->all('filter_label');
        $aliases = $request->request->all('filter_aliases');
        $colors  = $request->request->all('filter_color');
        $bands   = $request->request->all('filter_band');

        $filters = [];
        foreach ($labels as $i => $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            $aliasList = array_values(array_filter(
                array_map('trim', explode(',', $aliases[$i] ?? '')),
                fn(string $s) => $s !== ''
            ));
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', $colors[$i] ?? '') ? $colors[$i] : '#6c757d';
            $band  = ($bands[$i] ?? '') === 'NB' ? 'NB' : 'BB';
            $filters[] = ['label' => $label, 'color' => $color, 'band' => $band, 'aliases' => $aliasList];
        }
        $config->setFilters($filters);
    }

    private function saveSessionTemplate(Request $request, AppConfig $config, StoragePathResolver $resolver, TranslatorInterface $translator): void
    {
        $json = $request->request->get('session_template', '');
        $template = json_decode($json, true);

        if (!is_array($template) || !isset($template['tree'])) {
            $this->addFlash('danger', $translator->trans('settings.template.invalid_json'));
            return;
        }

        $errors = $resolver->validateTemplate($template);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->addFlash('danger', $error);
            }
            return;
        }

        $config->setSessionTemplate($template);
    }

    private function saveNotifications(Request $request, AppConfig $config): void
    {
        $rawEmails = $request->request->get('emails', '');
        $emails = array_values(array_filter(
            array_map('trim', explode("\n", $rawEmails)),
            fn(string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));

        $config->setSection('notifications', [
            'emails'             => $emails,
            'weather_cloud_max'  => (int) $request->request->get('weather_cloud_max', 40),
            'weather_wind_max'   => (float) $request->request->get('weather_wind_max', 8),
            'weather_precip_max' => (float) $request->request->get('weather_precip_max', 0.5),
            'min_useful_hours'   => (float) $request->request->get('min_useful_hours', 2),
            'min_moon_sep'       => (int) $request->request->get('min_moon_sep', 30),
        ]);
    }

    private function buildRoleMapFromTree(array $nodes, string $prefix, array &$map): void
    {
        foreach ($nodes as $node) {
            $name = $node['name'] ?? '';
            $path = $prefix !== '' ? $prefix . '/' . $name : $name;
            if (isset($node['role']) && $node['role'] !== '') {
                $map[$node['role']] = $path;
            }
            if (!empty($node['children'])) {
                $this->buildRoleMapFromTree($node['children'], $path, $map);
            }
        }
    }
}
