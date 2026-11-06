{{--
    Student ID card — item 14 design. Thin wrapper kept so every
    existing include of this partial renders the new common card:
    it builds the IdCardFactory card for $sr and hands over to the
    shared design partial (front + back). Hosts include
    partials/id_card_styles once per page.
--}}
@include('pages.support_team.partials.id_card_design', [
    'card' => \App\Support\IdCardFactory::forStudent($sr),
    'brand' => \App\Support\IdCardFactory::brand(),
])
