<?php

namespace App\Command;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Utils\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a project without going through the UI, so an install can be provisioned
 * unattended (container entrypoint, CI, seed script).
 */
class CreateProjectCommand extends Command
{
    protected static $defaultName = 'app:create-project';

    /**
     * @var SymfonyStyle
     */
    private $io;

    private $entityManager;
    private $projects;
    private $users;

    public function __construct(EntityManagerInterface $em, ProjectRepository $projects, UserRepository $users)
    {
        parent::__construct();

        $this->entityManager = $em;
        $this->projects = $projects;
        $this->users = $users;
    }

    protected function configure()
    {
        $this
            ->setDescription('Creates a new project for an existing user.')
            ->setHelp($this->getCommandHelp())
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the owning user (must already exist)')
            ->addArgument('name', InputArgument::REQUIRED, 'The project name')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Webhook token. Generated at random when omitted.')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        $name = $input->getArgument('name');
        $token = $input->getOption('token') ?: TokenGenerator::generate();

        $user = $this->validate($email, $name, $token);

        $project = new Project();
        $project->setUser($user);
        $project->setName($name);
        $project->setToken($token);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        $this->io->success(sprintf('Project "%s" created (ID: %s) for %s', $project->getName(), $project->getId(), $email));
        $this->io->writeln(sprintf('Webhook path: <info>/webhook/%s</info>', $project->getToken()));

        return Command::SUCCESS;
    }

    private function validate(string $email, string $name, string $token): User
    {
        if (empty($name)) {
            throw new InvalidArgumentException('The project name can not be empty.');
        }

        $user = $this->users->findOneBy(['email' => $email]);

        if (!$user) {
            throw new RuntimeException(sprintf('There is no user registered with the "%s" email. Create one with app:create-user.', $email));
        }

        if ($this->projects->findOneBy(['token' => $token])) {
            throw new RuntimeException('That token is already in use by another project.');
        }

        // The UI only ever shows a user's first project — see ProjectController::add.
        if ($this->projects->findOneBy(['user' => $user])) {
            $this->io->warning(sprintf('%s already owns a project. Only the first one is reachable in the UI.', $email));
        }

        return $user;
    }

    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command creates a project and prints its webhook path:
  <info>php %command.full_name%</info> <comment>admin@example.com "My Project"</comment>
Pass <comment>--token</comment> to pin a known webhook token instead of generating one — useful
when the SNS subscription is provisioned from the same script:
  <info>php %command.full_name%</info> <comment>admin@example.com "My Project" --token=my-fixed-token</comment>
HELP;
    }
}
