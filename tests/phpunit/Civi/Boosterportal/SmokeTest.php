<?php
namespace Civi\Boosterportal;

use Civi\Test;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function testExtensionIsInstalled(): void {
    $status = \CRM_Extension_System::singleton()->getManager()->getStatus('boosterportal');
    $this->assertSame(\CRM_Extension_Manager::STATUS_INSTALLED, $status);
  }

}
