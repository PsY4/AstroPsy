<?php

namespace App\Controller;

use App\Entity\Author;
use App\Repository\AuthorRepository;
use App\Service\AstrobinAPIService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthorController extends AbstractController
{
    #[Route('/authors', name: 'authors')]
    public function index(AuthorRepository $authorRepository): Response
    {
        return $this->render('author/index.html.twig', [
            'authors' => $authorRepository->findAll(),
        ]);
    }
    #[Route('/author/new', name: 'new_author')]
    public function new(Request $request, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $authorName = trim($request->request->get('authorName'));

        // Create the entity only if folder creation succeeded
        $newAuthor = new Author();
        $newAuthor->setName($authorName);
        $em->persist($newAuthor);
        $em->flush();

        $this->addFlash('success', $translator->trans('flash.author_created', ['%name%' => $authorName]));

        return $this->redirectToRoute('authors');

    }
}
