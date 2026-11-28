<?php
namespace Tests\Feature;
use Tests\TestCase;
final class ExampleTest extends TestCase {
 public function test_guest_is_redirected_to_login(): void {
  $this->get('/')->assertRedirect(route('login'));
 }
}
