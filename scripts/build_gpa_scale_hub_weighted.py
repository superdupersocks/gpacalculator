"""Build the WP-CLI script for GPA scale steps 1 and 2 (scripts/wp/gpa_scale_hub_weighted.php).

    python3 scripts/build_gpa_scale_hub_weighted.py [--with-theme-code] > run.php

--with-theme-code prepends the hub shortcodes from the repo's gpa-shortcodes.php (for previews before the theme
code is deployed); without it the script relies on the deployed theme.
"""
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
php = (REPO / "scripts" / "wp" / "gpa_scale_hub_weighted.php").read_text()
if "--with-theme-code" in sys.argv:
    src = (REPO / "child-theme" / "generatepress-child" / "gpa-shortcodes.php").read_text()
    block = src[src.index("/* ==========================================================================\n   /gpa-scale/ hub"):]
    block = block.replace("add_action( 'init', function () {\n\tadd_shortcode( 'gpa_scale_converter', 'gpa_scale_converter_shortcode' );\n\tadd_shortcode( 'gpa_scale_lookup', 'gpa_scale_lookup_shortcode' );\n} );",
                          "add_shortcode( 'gpa_scale_converter', 'gpa_scale_converter_shortcode' );\nadd_shortcode( 'gpa_scale_lookup', 'gpa_scale_lookup_shortcode' );")
    php = php.replace("<?php\n", "<?php\nif ( ! function_exists( 'gpa_scale_letter_rows' ) ) {\n" + block + "\n}\n", 1)
sys.stdout.write(php)
