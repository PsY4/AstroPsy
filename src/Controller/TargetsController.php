<?php

namespace App\Controller;

use App\Entity\Target;
use App\Repository\TargetRepository;
use App\Service\StoragePathResolver;
use App\Service\TelescopiusAPIService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TargetsController extends AbstractController
{
    public function __construct(private readonly StoragePathResolver $resolver) {}
    #[Route('/targets', name: 'browse_targets')]
    public function browseTargets(TargetRepository $targetRepo): Response
    {
        $targets = $targetRepo->findBy([], ['name' => 'ASC']);
        $stats   = $targetRepo->findAllStats();
        $authors = $targetRepo->findAuthorsPerTarget();

        return $this->render('browse/targets.html.twig', [
            'targets'          => $targets,
            'stats'            => $stats,
            'authorsPerTarget' => $authors,
        ]);
    }

    #[Route('/targets/refresh', name: 'targets_refresh')]
    public function refreshTargets(EntityManagerInterface $em, TranslatorInterface $translator): Response
    {

        $root = $this->resolver->getSessionsRoot();

        if (!is_dir($root)) {
            $this->addFlash('danger', $translator->trans('flash.sessions_root_not_found', ['%path%' => $root]));
            return $this->redirectToRoute('browse_targets');
        }

        $targetRepo = $em->getRepository(Target::class);

        // Index existing targets by path for quick lookup
        /** @var Target[] $existingTargets */
        $existingTargets = $targetRepo->findAll();
        $byPath = [];
        foreach ($existingTargets as $t) {
            $byPath[$t->getPath()] = $t;
        }
        $foundPaths = [];

        $finder = new Finder();
        $finder
            ->in($root)
            ->depth('== 0')
            ->directories()
            ->ignoreDotFiles(true);

        foreach ($finder as $dir) {
            $name = $dir->getBasename();
            if ($name === '--DOCUMENTS--') {
                continue;
            }

            $key = $name;
            $foundPaths[$key] = true;

            if (!isset($byPath[$key])) {
                // Create new target
                $target = new Target();
                $target
                    ->setName($name)
                    ->setPath($name)
                ;
                $em->persist($target);
                $byPath[$key] = $target;
            }
        }

        // Clean up targets that no longer exist on disk
        foreach ($existingTargets as $t) {
            $k = $t->getPath();
            if (!isset($foundPaths[$k])) {
                // Folder is gone => remove target
                $em->remove($t);
            }
        }

        $em->flush();
        $this->addFlash('success', $translator->trans('flash.targets_refreshed'));
        return $this->redirectToRoute('browse_targets');
    }

    #[Route('/targets/find-new-targets', name: 'targets_find_new')]
    public function findNewTargets(Request $request, TelescopiusAPIService $telescopiusAPIService): Response
    {
        $params = [
            'min_alt'      => (int) $request->query->get('min_alt', 65),
            'min_duration' => (int) $request->query->get('min_duration', 180),
            'moon_dist'    => (int) $request->query->get('moon_dist', 53),
            'mag_max'      => (int) $request->query->get('mag_max', 15),
        ];

        $newTargets = $telescopiusAPIService->getTargets(
            $params['min_alt'],
            $params['min_duration'],
            $params['moon_dist'],
            $params['mag_max'],
        );

        return $this->render('browse/new-targets.html.twig', [
            'targets' => $newTargets,
            'params'  => $params,
        ]);
    }
}
