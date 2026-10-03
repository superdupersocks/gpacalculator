# gpacalculator

Source for gpacalculator.net: the gpacalculator-manager plugin (`plugin/`, home of every
calculator and the shared core), the GeneratePress child theme (`child-theme/`, site design and
brand tokens) and the QA suite.

```
npm install
pip install -r tests/requirements.txt
python3 tests/run_all.py      # QA
bash scripts/package.sh       # zips in dist/
```

Architecture, calculator status and changelog: [PROJECT.md](PROJECT.md).
