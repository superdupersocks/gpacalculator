# gpacalculator

Source for gpacalculator.net: the GeneratePress child theme (`child-theme/`), the
gpacalculator-manager plugin (`plugin/`), the shared calculator core and the Playwright QA suite.

```
npm install
pip install -r tests/requirements.txt
python3 tests/run_all.py      # QA
bash scripts/package.sh       # zips in dist/
```

Architecture, calculator status and changelog: [PROJECT.md](PROJECT.md).
