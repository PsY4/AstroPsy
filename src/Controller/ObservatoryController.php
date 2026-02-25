<?php

namespace App\Controller;

use App\Entity\Observatory;
use App\Form\ObservatoryType;
use App\Form\SessionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Repository\ObservatoryRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ObservatoryController extends AbstractController
{
    #[Route('/observatories', name: 'observatories')]
    public function index(Request $req, EntityManagerInterface $em): Response
    {
        // Create form
        $newObs = new Observatory();
        $form = $this->createForm(ObservatoryType::class, $newObs);
        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($newObs);
            $em->flush();
            return $this->redirectToRoute('observatories');
        }

        $obsRepo       = $em->getRepository(Observatory::class);
        $observatories = $obsRepo->findBy([], ['name' => 'ASC']);

        $editForms = [];
        foreach ($observatories as $obs) {
            $editForms[$obs->getId()] = $this->createForm(ObservatoryType::class, $obs, [
                'action' => $this->generateUrl('edit_observatory', ['id' => $obs->getId()]),
            ])->createView();
        }

        return $this->render('observatory/index.html.twig', [
            'observatories' => $observatories,
            'form'          => $form->createView(),
            'editForms'     => $editForms,
        ]);
    }

    #[Route('/observatory/{id}/edit', name: 'edit_observatory', methods: ['POST'])]
    public function edit(int $id, Request $req, EntityManagerInterface $em): Response
    {
        $obs = $em->getRepository(Observatory::class)->find($id);
        if (!$obs) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ObservatoryType::class, $obs, [
            'action' => $this->generateUrl('edit_observatory', ['id' => $id]),
        ]);
        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
        }

        return $this->redirectToRoute('observatories');
    }

    #[Route('/observatory/{id}/favorite', name: 'toggle_favorite_observatory', methods: ['POST'])]
    public function toggleFavorite(int $id, EntityManagerInterface $em, ObservatoryRepository $obsRepo): Response
    {
        $obs = $obsRepo->find($id);
        if (!$obs) {
            throw $this->createNotFoundException();
        }

        // Unset all others
        foreach ($obsRepo->findAll() as $o) {
            $o->setFavorite($o->getId() === $obs->getId());
        }
        $em->flush();

        return $this->redirectToRoute('observatories');
    }
}
