<?php

use App\Services\Treasury\PaymentMethodNormalizer;

test('normalizer maps credit_card to card', function () {
    $normalizer = new PaymentMethodNormalizer();
    $result = $normalizer->normalize('credit_card');

    expect($result->raw)->toBe('credit_card')
        ->and($result->canonical)->toBe('card')
        ->and($result->known)->toBeTrue();
});

test('normalizer keeps sale payment methods as known canonicals', function () {
    $normalizer = new PaymentMethodNormalizer();

    foreach (['cash', 'card', 'bank_transfer', 'check', 'orange_money', 'wave'] as $method) {
        $result = $normalizer->normalize($method);
        expect($result->canonical)->toBe($method)
            ->and($result->known)->toBeTrue();
    }
});

test('normalizer keeps mobile_money distinct from orange_money and wave', function () {
    $normalizer = new PaymentMethodNormalizer();
    $mobile = $normalizer->normalize('mobile_money');

    expect($mobile->canonical)->toBe('mobile_money')
        ->and($mobile->known)->toBeTrue()
        ->and($normalizer->normalize('orange_money')->canonical)->not->toBe('mobile_money');
});

test('normalizer preserves unknown values without silent mapping', function () {
    $normalizer = new PaymentMethodNormalizer();
    $result = $normalizer->normalize('legacy_petty_cash');

    expect($result->raw)->toBe('legacy_petty_cash')
        ->and($result->canonical)->toBe('legacy_petty_cash')
        ->and($result->known)->toBeFalse();
});

test('normalizer rawValuesMatchingCanonical includes aliases', function () {
    $normalizer = new PaymentMethodNormalizer();

    expect($normalizer->rawValuesMatchingCanonical('card'))
        ->toEqualCanonicalizing(['card', 'credit_card']);
});
