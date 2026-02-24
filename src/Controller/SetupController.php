<?php

namespace App\Controller;

use App\Entity\Setup;
use App\Entity\SetupPart;
use App\Form\SetupType;
use App\Service\StoragePathResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SetupController extends AbstractController
{
    public function __construct(private readonly StoragePathResolver $resolver) {}
    #[Route('/setup/list', name: 'setup_list')]
    public function list(EntityManagerInterface $em): Response
    {
        $setups = $em->getRepository(Setup::class)->findAll();

        return $this->render('setup/list.html.twig', [
            'setups' => $setups,
        ]);
    }

    #[Route('/setup/new', name: 'setup_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            setup: new Setup(),
            template: 'setup/setup.html.twig'
        );
    }

    #[Route('/setup/{setup}/edit', name: 'setup_edit')]
    public function edit(Setup $setup, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            setup: $setup,
            template: 'setup/setup.html.twig'
        );
    }


    private function handleForm(
        Request                $request,
        EntityManagerInterface $em,
        Setup                  $setup,
        string                 $template
    ): Response {
        $form = $this->createForm(SetupType::class, $setup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $documentsPath = $this->resolver->getDocumentsPath();

            /** @var Setup $setup */
            $setup = $form->getData();

            // filtersConfig is stored as JSON via a hidden field
            $filtersRaw = $form->get('filtersConfigRaw')->getData();
            if ($filtersRaw !== null && $filtersRaw !== '') {
                $setup->setFiltersConfig(json_decode($filtersRaw, true) ?: []);
            }

            foreach ($setup->getSetupParts() as $setupPart) {
                $setupPart->setSetup($setup);
            }
            // Parts images upload + deletion
            foreach ($form->get('setupParts') as $partForm) {
                /** @var SetupPart $setupPart */
                $setupPart = $partForm->getData();

                $currentImages = is_array($setupPart->getImages()) ? $setupPart->getImages() : [];

                // Remove images marked for deletion
                $deleteRaw = $partForm->get('deleteImages')->getData();
                if ($deleteRaw) {
                    $toDelete = array_filter(explode(',', $deleteRaw));
                    foreach ($toDelete as $filename) {
                        $filePath = Path::join($documentsPath, $filename);
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                    $currentImages = array_values(array_diff($currentImages, $toDelete));
                }

                // Upload new images
                /** @var UploadedFile[] $uploadedFiles */
                $uploadedFiles = $partForm->get('images')->getData();
                foreach ($uploadedFiles as $file) {
                    $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $newFilename = $originalFilename . '-' . uniqid() . '.' . $file->guessExtension();
                    $file->move($documentsPath, $newFilename);
                    $currentImages[] = $newFilename;
                }

                $setupPart->setImages($currentImages);
            }

            // Setup logo
            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $form->get('uploadLogo')->getData();
            if ($uploadedFile) {
                $newFilename = $uploadedFile->getClientOriginalName();
                $uploadedFile->move(
                    $documentsPath,
                    $newFilename
                );
                $setup->setLogo($newFilename);
            }

            $em->persist($setup);
            $em->flush();
            return $this->redirectToRoute('setup_list');
        }

        return $this->render($template, [
            'form' => $form->createView(),
            'setup'  => $setup
        ]);
    }
}
