{{-- ID card screen-only gradient finish (item 14). Included ONLY
     by the on-screen host views, never by the PDF host: dompdf
     paints NOTHING for a background whose value chain reaches a
     linear-gradient (proven by rendered test pages, 2026-11-01),
     and its media type is screen, so a media query cannot shield
     the PDF. The shared styles carry the solid colours; this
     partial layers the gradients on top for real browsers. --}}
<style>
    .idc2-student .idc2-band,.idc2-student .idc2-bhead{background:linear-gradient(135deg,#1a5a8f,#0b2a4c 70%)}
    .idc2-student .idc2-accent{background:linear-gradient(90deg,#17507f,#2f8ded,#17507f)}
    .idc2-teacher .idc2-band,.idc2-teacher .idc2-bhead{background:linear-gradient(135deg,#0e7a5f,#074f3c 70%)}
    .idc2-teacher .idc2-accent{background:linear-gradient(90deg,#0e7a5f,#21a67e,#0e7a5f)}
    .idc2-principal .idc2-band,.idc2-principal .idc2-bhead{background:linear-gradient(135deg,#5b2ea6,#3d1c73 70%)}
    .idc2-principal .idc2-accent{background:linear-gradient(90deg,#5b2ea6,#8358d6,#5b2ea6)}
    .idc2-admin .idc2-band,.idc2-admin .idc2-bhead{background:linear-gradient(135deg,#8a6414,#5f430c 70%)}
    .idc2-admin .idc2-accent{background:linear-gradient(90deg,#8a6414,#c1922a,#8a6414)}
    .idc2-accountant .idc2-band,.idc2-accountant .idc2-bhead{background:linear-gradient(135deg,#b4502a,#83351a 70%)}
    .idc2-accountant .idc2-accent{background:linear-gradient(90deg,#b4502a,#d96f42,#83351a)}
    .idc2-librarian .idc2-band,.idc2-librarian .idc2-bhead{background:linear-gradient(135deg,#0e7490,#155e75 70%)}
    .idc2-librarian .idc2-accent{background:linear-gradient(90deg,#0e7490,#22a7cc,#155e75)}
    .idc2-seal{background:linear-gradient(135deg,#f3d67c,#dfae3e)}
</style>
