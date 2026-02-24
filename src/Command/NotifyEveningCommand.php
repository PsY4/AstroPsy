<?php
namespace App\Command;

use App\Entity\Target;
use App\Repository\ObservatoryRepository;
use App\Repository\TargetRepository;
use App\Service\AppConfig;
use App\Service\AstroNightService;
use App\Service\AstropyClient;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(name: 'app:notify:evening', description: 'Send evening notification if conditions are favorable')]
class NotifyEveningCommand extends Command
{
    public function __construct(
        private readonly AstropyClient          $astropyClient,
        private readonly ObservatoryRepository  $obsRepo,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface        $mailer,
        private readonly AppConfig              $config,
        private readonly AstroNightService      $astroNight,
        private readonly NotificationService    $notificationService,
        private readonly TargetRepository       $targetRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print email body without sending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        $obs = $this->obsRepo->findOneBy(['favorite' => true]);
        if (!$obs) {
            $output->writeln('No favorite observatory configured.');
            return Command::SUCCESS;
        }

        $notif = $this->config->getSection('notifications');
        try {
            $forecast = json_decode($this->astropyClient->forecast($obs->getLat(), $obs->getLon()), true);
        } catch (\Throwable $e) {
            $output->writeln('Weather service unavailable: ' . $e->getMessage());
            return Command::SUCCESS;
        }
        $weatherAlert = $this->astroNight->computeWeatherAlert($forecast, $notif);

        if (!$weatherAlert['favorable'] && !$dryRun) {
            $output->writeln('Conditions unfavorable — no notification sent.');
            return Command::SUCCESS;
        }

        $emails = $notif['emails'] ?? ['admin@astropsy.local'];

        // Compute visibility windows for wishlist targets
        $wishlistTargets = $this->targetRepo->findWishlistWithCoordinates();

        $lat     = (float) $obs->getLat();
        $lon     = (float) $obs->getLon();
        $horizon = (float) ($obs->getAltitudeHorizon() ?? 20);
        $baseDate = new \DateTimeImmutable('today midnight UTC');

        $nightData = [];
        /** @var Target $t */
        foreach ($wishlistTargets as $t) {
            $raDeg = $t->getRa() * 15.0;
            $night = $this->astroNight->computeNight($lat, $lon, $horizon, $raDeg, $t->getDec(), $baseDate);
            if ($night['usefulH'] > 0) {
                $nightData[] = array_merge($night, ['target' => $t]);
            }
        }

        usort($nightData, fn($a, $b) => $b['usefulH'] <=> $a['usefulH']);
        $top5 = array_slice($nightData, 0, 5);

        $targetLines = array_map(fn($d) => sprintf(
            '  ⭐ %-22s  %s → %s  (%.1fh)',
            $d['target']->getName(),
            $d['windowStart'] ?? '?',
            $d['windowEnd']   ?? '?',
            $d['usefulH']
        ), $top5);

        if (empty($targetLines)) {
            $targetLines = ['  (aucune cible observable ce soir)'];
        }

        $body = sprintf(
            "🌙 AstroPsy — Alerte météo favorable ce soir !\n\n" .
            "Observatoire : %s\n" .
            "Nuages : %d%%  |  Vent : %.1f m/s  |  Précip. : %.1f mm\n" .
            "Score moyen : %d/100\n\n" .
            "Top 5 cibles observables ce soir :\n%s\n\n" .
            "→ Night Planner : http://astro.alexetaurore.com/night-planner",
            $obs->getName(),
            $weatherAlert['cloud_avg'],
            $weatherAlert['wind_max'],
            $weatherAlert['precip'],
            $weatherAlert['score_avg'] ?? 0,
            implode("\n", $targetLines),
        );

        if ($dryRun) {
            $output->writeln($body);
            $output->writeln('[dry-run] Notification NOT persisted to DB.');
            return Command::SUCCESS;
        }

        // Persist notification to DB
        $this->notificationService->createEveningAlert($weatherAlert, $obs->getName(), $top5);

        $email = (new Email())
            ->from('astropsy@astropsy.local')
            ->to(...$emails)
            ->subject('🌙 AstroPsy — Conditions favorables ce soir !')
            ->text($body);

        $this->mailer->send($email);

        $output->writeln('Notification sent to ' . implode(', ', $emails));
        return Command::SUCCESS;
    }

}
