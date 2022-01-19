<?php
declare(strict_types=1);

namespace App\Command;

use App\Cache\IpAddressCache;
use App\Constants\RobotConstant;
use App\Model\Contact\Contact;
use App\Repository\ExampleRepository;
use App\Repository\RobotRepository;
use App\Service\RobotService;
use App\Support\IpAddress;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

/**
 * @Command
 */
class TestCommand extends HyperfCommand
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        parent::__construct('test:command');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Hyperf Demo Command');
    }

    public function handle()
    {
        // $repository = di()->get(ExampleRepository::class);
        // $repository->where_case2();

        // di()->get(RobotService::class)->create([
        //     'robot_name' => "登录助手",
        //     'describe'   => "异地登录助手",
        //     'logo'       => '',
        //     'is_talk'    => 0,
        //     'type'       => 1,
        // ]);
    }
}
