<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>ID Cards</title>
    {{-- Item 14: bulk ID cards on the common design — one card per
         CR80-vertical page pair (front page, then back page), built
         by IdCardFactory and rendered by the shared design partial.
         Used for both student bulk and staff bulk. --}}
    @include('pages.support_team.partials.id_card_styles')
    <style>
        @page { size: 153pt 242.6pt; margin: 0; }
        html, body { margin: 0; padding: 0; }
        .idc2 { border: none; border-radius: 0; page-break-after: always; page-break-inside: avoid; height: 240pt; }
        .idc2.idc2-back { page-break-after: always; }
        .idc2.idc2-last { page-break-after: auto; }
    </style>
</head>
<body>
    @foreach($cards as $card)
        @include('pages.support_team.partials.id_card_design', ['card' => $card, 'brand' => $brand, 'idcLast' => $loop->last])
    @endforeach
</body>
</html>
