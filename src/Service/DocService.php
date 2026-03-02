<?php
namespace App\Service;

use App\Entity\Doc;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

class DocService {

    public function __construct(
        private readonly StoragePathResolver $resolver,
    ) {}

    /**
     * Generate a safe, unique filename from a human-readable name.
     */
    public function generateSafeFilename(string $name, string $ext): string
    {
        $slugger = new AsciiSlugger();
        $slug = $slugger->slug($name)->lower()->toString();
        $slug = mb_substr($slug, 0, 100);

        return $slug . '-' . uniqid() . '.' . $ext;
    }

    /**
     * Reject any filename containing path traversal characters.
     *
     * @throws \InvalidArgumentException
     */
    public function validateFilename(string $filename): void
    {
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new \InvalidArgumentException(sprintf('Invalid filename: "%s"', $filename));
        }
    }

    /**
     * Save a markdown document to storage with a safe unique filename.
     * Sets the doc path on the entity and returns the generated filename.
     */
    public function saveDocToStorage(Doc $doc): string
    {
        $fileName = $this->generateSafeFilename($doc->getTitle() ?? 'document', 'md');
        $fileContent = $doc->getDoc();
        $documentsPath = $this->resolver->getDocumentsPath();

        $filesystem = new Filesystem();

        try {
            if (!$filesystem->exists($documentsPath)) {
                $filesystem->mkdir($documentsPath, 0755);
            }

            $filePath = Path::join($documentsPath, $fileName);
            file_put_contents($filePath, $fileContent);

            $doc->setPath($fileName);

        } catch (IOExceptionInterface $exception) {
            throw new \RuntimeException(sprintf(
                'Failed to save the document to "%s": %s',
                $documentsPath,
                $exception->getMessage()
            ));
        }

        return $fileName;
    }

    /**
     * Move an uploaded file to --DOCUMENTS-- with a safe unique name.
     * Returns the generated filename.
     */
    public function moveUploadedFile(UploadedFile $file): string
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $ext = $file->guessExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);

        $newFilename = $this->generateSafeFilename($originalName, $ext);
        $documentsPath = $this->resolver->getDocumentsPath();

        $filesystem = new Filesystem();
        if (!$filesystem->exists($documentsPath)) {
            $filesystem->mkdir($documentsPath, 0755);
        }

        $file->move($documentsPath, $newFilename);

        return $newFilename;
    }
}
