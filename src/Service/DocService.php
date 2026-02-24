<?php
namespace App\Service;

use App\Entity\Doc;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class DocService {

    public function __construct(
        private readonly StoragePathResolver $resolver,
    ) {}

    function saveDocToStorage(Doc $doc) {
        $fileName = $doc->getTitle().".md";
        $fileContent = $doc->getDoc();
        $documentsPath = $this->resolver->getDocumentsPath();

        // Ensure the directory exists
        $filesystem = new Filesystem();

        try {
            if (!$filesystem->exists($documentsPath)) {
                $filesystem->mkdir($documentsPath, 0755);
            }

            // Build full file path
            $filePath = Path::join($documentsPath, $fileName);

            // Save the file
            file_put_contents($filePath, $fileContent);

        } catch (IOExceptionInterface $exception) {
            // Log or rethrow as needed
            throw new \RuntimeException(sprintf(
                'Failed to save the document to "%s": %s',
                $documentsPath,
                $exception->getMessage()
            ));
        }
    }

}