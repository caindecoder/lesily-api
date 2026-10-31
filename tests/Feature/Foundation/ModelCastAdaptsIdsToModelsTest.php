<?php

namespace Tests\Feature\Foundation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Casts\Uncastable;
use App\Billing\Invoices\Entities\Invoice;
use Spatie\LaravelData\Support\DataConfig;
use App\Foundation\UseCases\Casts\ModelCast;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Creation\CreationContextFactory;
use App\Billing\Invoices\UseCases\Commands\DownloadInvoiceReceiptCommand;

/**
 * @testclass-carveout: adapter — the cast's branches no command reaches through a feature
 * walk (a non-model class, a column list, a model passed through); the lookup and its miss
 * are proven by the receipt download (§2.14 Rule B).
 */
class ModelCastAdaptsIdsToModelsTest extends FoundationTestCase
{
    #[Test]
    public function when_the_cast_names_a_class_that_is_not_a_model_then_it_declines(): void
    {
        // Given a cast naming a class that is not a model
        $cast = new ModelCast(CarbonImmutable::class);

        // When it casts an id read for the receipt command's invoice
        $outcome = $this->castForTheReceiptInvoice($cast, (string) Str::uuid());

        // Then it declines and leaves the value to the next cast
        $this->assertSame(Uncastable::create(), $outcome);
    }

    #[Test]
    public function when_the_cast_names_columns_then_only_those_are_loaded(): void
    {
        // Given a stored invoice and a cast naming only its key column
        $invoiceId = Invoice::factory()->create()->getKey();
        $cast = new ModelCast(Invoice::class, ['id']);

        // When the cast loads the invoice by its id
        $outcome = $this->castForTheReceiptInvoice($cast, $invoiceId);

        // Then the loaded invoice holds the key column alone
        $this->assertInstanceOf(Invoice::class, $outcome);
        $this->assertSame(['id' => $invoiceId], $outcome->getAttributes());
    }

    #[Test]
    public function when_the_value_is_already_a_model_then_the_same_instance_passes_through(): void
    {
        // Given an invoice already loaded by its caller
        $invoice = Invoice::factory()->create();

        // When the invoice cast receives the model itself
        $outcome = $this->castForTheReceiptInvoice(new ModelCast(Invoice::class), $invoice);

        // Then the caller's instance comes back untouched
        $this->assertSame($invoice, $outcome);
    }

    protected function castForTheReceiptInvoice(ModelCast $cast, mixed $value): mixed
    {
        $property = app(DataConfig::class)->getDataClass(DownloadInvoiceReceiptCommand::class)->properties
            ->firstOrFail(fn (DataProperty $property): bool => $property->name === 'invoice');
        $context = CreationContextFactory::createFromConfig(DownloadInvoiceReceiptCommand::class)->get();

        return $cast->cast($property, $value, [], $context);
    }
}
