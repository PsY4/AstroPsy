<?php

namespace App\Controller;

use App\Entity\Target;
use App\Entity\Setup;
use App\Entity\WishListEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class WishListFramingController extends AbstractController
{
    #[Route('/target/{id}/framing', name: 'target_framing_get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $em): JsonResponse
    {
        $target = $em->getRepository(Target::class)->find($id);
        if (!$target) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $entry = $em->getRepository(WishListEntry::class)->findOneBy(['target' => $target]);

        return new JsonResponse([
            'ra'              => $entry?->getRaFraming() ?? $target->getRa(),
            'dec'             => $entry?->getDecFraming() ?? $target->getDec(),
            'rotation'        => $entry?->getRotationAngle() ?? 0.0,
            'setupId'         => $entry?->getSetup()?->getId(),
            'filtersSelected' => $entry?->getFiltersSelected(),
            'targetName'      => $target->getName(),
        ]);
    }

    #[Route('/target/{id}/framing', name: 'target_framing_save', methods: ['POST'])]
    public function save(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $target = $em->getRepository(Target::class)->find($id);
        if (!$target) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $entry = $em->getRepository(WishListEntry::class)->findOneBy(['target' => $target]);
        if (!$entry) {
            $entry = new WishListEntry();
            $entry->setTarget($target);
            $em->persist($entry);
        }

        if (array_key_exists('ra', $data)) {
            $entry->setRaFraming($data['ra'] !== null ? (float) $data['ra'] : null);
        }
        if (array_key_exists('dec', $data)) {
            $entry->setDecFraming($data['dec'] !== null ? (float) $data['dec'] : null);
        }
        if (array_key_exists('rotation', $data)) {
            $entry->setRotationAngle($data['rotation'] !== null ? (float) $data['rotation'] : null);
        }
        if (array_key_exists('setupId', $data)) {
            $setup = $data['setupId'] ? $em->getRepository(Setup::class)->find((int) $data['setupId']) : null;
            $entry->setSetup($setup);
        }
        if (array_key_exists('filtersSelected', $data)) {
            $entry->setFiltersSelected(is_array($data['filtersSelected']) && count($data['filtersSelected']) > 0
                ? array_values(array_map('intval', $data['filtersSelected']))
                : null
            );
        }

        $em->flush();

        return new JsonResponse(['ok' => true]);
    }
}
