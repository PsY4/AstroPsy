<?php

namespace App\Command;

use App\Repository\SessionRepository;
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
    name: 'app:update-astrobin-images',
    description: 'Updates AstroBin informations about sessions',
)]
class UpdateAstrobinImagesCommand extends Command
{
    private AstrobinAPIService $astrobinAPIService;
    private SessionRepository $sessionRepository;
    private EntityManagerInterface $em;

    public function __construct(AstrobinAPIService $astrobinAPIService, SessionRepository $sessionRepository, EntityManagerInterface $em)
    {
        parent::__construct();
        $this->astrobinAPIService = $astrobinAPIService;
        $this->sessionRepository = $sessionRepository;
        $this->em = $em;
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $allSessions = $this->sessionRepository->findAll();
        foreach ($allSessions as $session) {
            if ($session->getAstrobin() != "") {
                try {
                    $io->note('Updating #'.$session->getId());
                    $session->setAstrobinStats($this->astrobinAPIService->getImage($session->getAstrobin()));
                    $this->em->persist($session);
                    $this->em->flush();
                    $io->note('Updated !');
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Session #%d skipped: %s', $session->getId(), $e->getMessage()));
                    continue;
                }
            }
        }


        $io->success('Images updated.');

        return Command::SUCCESS;
    }
}
