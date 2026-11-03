Current UI typography: Segoe UI, Arial, sans-serif, matching the local Mutiara
payroll reference. The stack is defined in ../css/typography.css and uses system
fonts without external font requests. Export templates use the same preference.
On Windows, PDF renderers register the installed Segoe UI faces in their normal
font cache. DejaVu Sans remains the embedded fallback on hosts without Segoe UI.

Payment and savings receipts keep their original DejaVu Serif / Times New Roman
typography in previews, printed slips, PDF and PNG output. Receipt toolbar controls
continue to use the UI font.

The Inter/Outfit files below are retained for historical assets and licensing.
The main application stylesheet no longer imports local_fonts.css.

Inter and Outfit are the same font families and weights previously requested by
the application's Google Fonts stylesheet. They are served locally so menu
rendering does not depend on an external font stylesheet or font server.

Source stylesheet (retrieved 2026-10-21):
https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap

The WOFF2 files are unmodified downloads from the stylesheet's fonts.gstatic.com
URLs. Hashed filenames identify their source URLs. Font faces, subset coverage,
weights, and `font-display: swap` are retained in `../css/local_fonts.css`.

Licenses: `inter-OFL.txt` and `outfit-OFL.txt` (SIL Open Font License 1.1), from
https://github.com/google/fonts/tree/main/ofl/inter and
https://github.com/google/fonts/tree/main/ofl/outfit.
