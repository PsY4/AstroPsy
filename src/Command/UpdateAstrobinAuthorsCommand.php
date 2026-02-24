<?php

namespace App\Command;

use App\Repository\AuthorRepository;
use App\Service\AstrobinAPIService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-astrobin-authors',
    description: 'Updates AstroBin informations about authors',
)]
class UpdateAstrobinAuthorsCommand extends Command
{
    private AstrobinAPIService $astrobinAPIService;
    private AuthorRepository $authorRepository;
    private EntityManagerInterface $em;

    public function __construct(AstrobinAPIService $astrobinAPIService, AuthorRepository $authorRepository, EntityManagerInterface $em)
    {
        parent::__construct();
        $this->astrobinAPIService = $astrobinAPIService;
        $this->authorRepository = $authorRepository;
        $this->em = $em;
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $allAuthors = $this->authorRepository->findAll();
        foreach ($allAuthors as $author) {
            if($author->getAstrobinId() != "") {
                try {
                    $io->note('Updating #'.$author->getId());

                    $infos = $this->astrobinAPIService->getProfileInfos($author->getAstrobinId());
                    $author->setAstrobinProfile($infos);
                    $stats = $this->astrobinAPIService->getProfileStats($author->getAstrobinId());
                    $author->setAstrobinStats($stats);

                    $this->em->persist($author);
                    $this->em->flush();
                    $io->note('Updated !');
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Author #%d skipped: %s', $author->getId(), $e->getMessage()));
                    continue;
                }
            }
        }

        return Command::SUCCESS;
    }
}
