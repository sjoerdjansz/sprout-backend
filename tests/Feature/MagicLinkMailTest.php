<?php

namespace Tests\Feature;

use App\Mail\MagicLinkMail;
use Tests\TestCase;

class MagicLinkMailTest extends TestCase
{
    public function test_mail_greets_the_user_and_contains_the_link_and_its_lifetime(): void
    {
        $mail = new MagicLinkMail('Ada', 'http://localhost:5173/auth/verify?token=abc');

        $mail->assertHasSubject('Your Sprout sign in link');
        $mail->assertSeeInHtml('Hi Ada,');
        $mail->assertSeeInHtml('http://localhost:5173/auth/verify?token=abc');
        $mail->assertSeeInText('The link works once and expires in 15 minutes.');
    }
}
