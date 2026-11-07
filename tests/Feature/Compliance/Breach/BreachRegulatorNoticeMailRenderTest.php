<?php

namespace Tests\Feature\Compliance\Breach;

use RuntimeException;
use Illuminate\Mail\Mailables\Address;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use App\Compliance\Breach\Mail\BreachRegulatorNoticeMail;

/**
 * Mail::fake() short-circuits delivery, so the filing tests prove what the mail
 * says only through render(); these tests pin its words, subject and retries.
 *
 * @testclass-carveout: mail-render — the class under test IS the behaviour: a
 * Mailable has no HTTP seam, so its rendering can only be proven directly.
 */
class BreachRegulatorNoticeMailRenderTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_the_regulator_mail_delivery_fails_then_the_failure_is_reported(): void
    {
        // Given
        Exceptions::fake();
        $mail = $this->aRegulatorMail();

        // When the queued mail exhausts its retries
        $mail->failed(new RuntimeException('Mail server unavailable'));

        // Then the failure is reported for an admin to resend, with the retry config intact
        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame([3, [1, 5, 15]], [$mail->tries, $mail->backoff]);
    }

    #[Test]
    public function when_built_then_it_renders_from_the_markdown_template(): void
    {
        // Given
        $mail = $this->aRegulatorMail();

        // When
        $content = $mail->content();

        // Then
        $this->assertSame(['emails.breach-regulator-notice', null], [$content->markdown, $content->view]);
    }

    #[Test]
    public function when_rendered_then_the_body_reads_every_fact_of_the_companys_part(): void
    {
        // Given
        $mail = $this->aRegulatorMail();

        // When
        $body = $mail->render();

        // Then every fact of the company's part reads in the notice
        $this->assertStringContainsString(
            'Lesily, the data processor, notifies the Data Protection Authority of a breach of personal data on behalf of Kandy Tea Estates, the data controller, at its written request. '
            .'It was discovered on 4 May 2026, 14:00 UTC; the notification deadline is 7 May 2026, 14:00 UTC. '
            .'What happened: A payroll export was emailed to the wrong recipient. '
            .'Likely consequences: Salary details may be read by one outside person. '
            .'Measures taken or planned: The recipient deleted the file. '
            .'Personal data affected: Payroll records, Bank details. People affected: 27.',
            $body,
        );
    }

    #[Test]
    public function when_built_then_its_subject_names_the_company(): void
    {
        // Given
        $mail = $this->aRegulatorMail();

        // When
        $envelope = $mail->envelope();

        // Then
        $this->assertSame('Personal data breach notification for Kandy Tea Estates', $envelope->subject);
        $this->assertEquals([new Address((string) config('mail.from.address'))], $envelope->replyTo);
    }

    protected function aRegulatorMail(): BreachRegulatorNoticeMail
    {
        return new BreachRegulatorNoticeMail('Kandy Tea Estates', [
            'discovered' => '4 May 2026, 14:00 UTC',
            'deadline' => '7 May 2026, 14:00 UTC',
            'description' => 'A payroll export was emailed to the wrong recipient.',
            'consequences' => 'Salary details may be read by one outside person.',
            'measures' => 'The recipient deleted the file.',
            'data_types' => 'Payroll records, Bank details',
            'count' => '27',
        ]);
    }
}
