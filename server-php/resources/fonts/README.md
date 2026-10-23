# Fonts embedded in exported PDFs

Copied unchanged from insights-aicountly `server-php/resources/fonts/` (with `Pdf\TrueTypeFont`,
`Pdf\PdfDocument` and `Pdf\PdfText`, ported from its `Reports` namespace) for the purchase order and the
dashboard PDFs (launch-readiness P1-P4).

`DejaVuSans.ttf` and `DejaVuSans-Bold.ttf` are subsets of DejaVu Sans 2.37 (Debian
`fonts-dejavu-core` 2.37-8; originals sha256 `ae7b7855…837280` and `5c1247ac…2ce895`). The
licence is in `LICENSE-DejaVu.txt` (Bitstream Vera licence; DejaVu changes are public domain).
The family name is unchanged: it contains neither "Bitstream" nor "Vera", as the licence requires.

Why: the PDF renderer used the base-fourteen Helvetica, which can only write WinAnsi, so a report
dropped "é", "ü", "–" and printed "₹" as "INR"/"?" (ANA-13, D-19). `Pdf\TrueTypeFont` embeds
only the glyphs a report uses (a subset again, per file), with a ToUnicode map so the text can be
searched and copied.

Coverage: Latin (Basic, Latin-1, Extended-A/B, Extended Additional), combining marks, Greek,
general punctuation, currency signs (₹ €), letterlike symbols, arrows, mathematical operators,
geometric shapes. Indic scripts are NOT included: drawing Devanagari or Tamil correctly needs a
shaping engine (conjuncts, reordered vowel signs), and printing unshaped glyphs would misspell a
name. The renderer prints a visible marker for those characters instead of dropping them.

Regenerate (fontTools 4.66.1):

    pyftsubset DejaVuSans.ttf --unicodes="U+0020-007E,U+00A0-024F,U+0300-036F,U+0370-03FF,\
    U+1E00-1EFF,U+2000-206F,U+20A0-20CF,U+2100-214F,U+2190-21FF,U+2200-22FF,U+2300-23FF,\
    U+25A0-25FF,U+2600-2610,U+2713-2717,U+FFFD" --layout-features='' --no-hinting \
    --name-IDs='*' --name-legacy --name-languages='*' \
    --drop-tables+=GSUB,GPOS,GDEF,kern,FFTM,MATH --output-file=DejaVuSans.ttf
