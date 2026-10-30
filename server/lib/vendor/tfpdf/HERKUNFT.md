# tFPDF – Herkunft

PDF-Erzeugung für die Exporte (O-35, E-56; Admin 2026-10-30: fertige Bibliothek statt eigenem Schreiber).

| | |
|---|---|
| Bibliothek | tFPDF 1.34 (auf Basis von FPDF 1.85), Ian Back, Tycho Veltmeijer, Olivier Plathey |
| Quelle | https://github.com/Setasign/tFPDF (Spiegel der offiziellen Fassung von http://fpdf.org/en/script/script92.php), Commit `69b06444b929e0d54ffbb5efcadfe97ecbad831b` |
| Lizenz | LGPL-2.1 (Text: `LGPL-2.1.txt`), verträglich mit der AGPL-3.0 dieses Projekts |
| Dateien | `tfpdf.php` (SHA-256 `beced50c8a34354436c48e53f11b27001245776a314efdcca34e283d8d3d96ad`), `font/unifont/ttfonts.php` hier als `ttfonts.php` (SHA-256 `113790ec67ad76f084fdb6dc244d420c6fc1e0769139d70396977278d1d6ef36`) |
| Änderungen | keine; die DejaVu-Schriften und Beispiele sind nicht übernommen |

Eingebunden über `server/lib/export_lib.php`. Schriften und Logo für die PDFs liegen in `server/pdf/` (Branding Allgäu Akut, Schriften unter OFL). Den Zwischenspeicher der Schriftmaße schreibt tFPDF in den Laufzeitordner (`var/pdf/`).

Aktualisieren: neue Fassung von fpdf.org bzw. dem Spiegel holen, die zwei Dateien ersetzen, Fassung, Commit und Prüfsummen hier eintragen, `lauf.php` und den Browser-Durchlauf fahren.
