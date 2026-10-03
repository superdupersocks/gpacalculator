(function () {
  'use strict';

  /* =========================================================================
   * INTERNATIONAL GRADE CONVERTER — UNIVERSAL ENGINE v1.0.0
   * A config-driven grade conversion engine. The engine knows how to
   * render, validate, calculate, explain and fail safely. It does NOT
   * know academic rules for any country — those live in country configs.
   *
   * Config loading (in priority order):
   *   1. options.config — a pre-parsed config object (WordPress shortcode)
   *   2. data-config-url — a full URL to a country config JSON
   *   3. data-config-base + data-country — base URL + slug + ".json"
   *   4. data-country — falls back to "configs/<slug>.json" (dev only)
   *
   * Country manifest:
   *   data-countries-url — URL to countries.json
   *   options.countries   — pre-parsed manifest array
   *   fallback: fetch "configs/countries.json" (dev only)
   * ========================================================================= */

  var ENGINE_VERSION = '1.1.0';
  var STORAGE_KEY = 'igc-converter-v1';
  var uidCounter = 0;

  function uid() { uidCounter++; return 'igc-' + Date.now().toString(36) + uidCounter + Math.random().toString(36).slice(2, 5); }

  /* --- HTML escaping --- */
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c];
    });
  }

  /* --- Number parsing (handles decimal comma) --- */
  function parseNumber(value) {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    var s = String(value).trim().replace(',', '.');
    if (!s) return null;
    var n = parseFloat(s);
    return Number.isFinite(n) ? n : null;
  }

  function formatGpa(value) {
    if (value === null || value === undefined || !Number.isFinite(value)) return '\u2014';
    return value.toFixed(1);
  }

  function formatGrade(value, precision) {
    if (value === null || value === undefined || !Number.isFinite(value)) return '\u2014';
    if (precision === 0) return String(Math.round(value));
    return String(value);
  }

  function sanitizeNumericInput(value, allowDecimal) {
    var s = String(value);
    var cleaned = '';
    var dotSeen = false;
    for (var i = 0; i < s.length; i++) {
      var ch = s[i];
      if (ch >= '0' && ch <= '9') { cleaned += ch; }
      else if (allowDecimal && ch === '.') { if (!dotSeen) { cleaned += ch; dotSeen = true; } }
      else if (allowDecimal && ch === ',') { if (!dotSeen) { cleaned += '.'; dotSeen = true; } }
      else if (ch === ' ' || ch === '\t') { continue; }
      else { continue; }
    }
    return cleaned;
  }

  function isValidDecimal(value, allowDecimalComma) {
    var s = String(value).trim();
    if (allowDecimalComma === false) {
      // Only dot accepted as decimal separator
      if (!/^\d+(\.\d+)?$/.test(s)) return false;
      return true;
    }
    s = s.replace(',', '.');
    if (!s) return true;
    return /^\d+(\.\d+)?$/.test(s);
  }

  function csvSafe(value) {
    var s = String(value == null ? '' : value);
    if (/^[=+\-@\t\r]/.test(s)) return "'" + s;
    return s;
  }

  function download(name, type, content) {
    var blob = new Blob([content], { type: type });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url; link.download = name;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

  /* --- Deep merge for config defaults --- */
  function defaults(obj, fallback) {
    if (obj == null) return fallback;
    if (typeof obj !== 'object' || typeof fallback !== 'object') return obj;
    var result = Array.isArray(fallback) ? obj.slice() : {};
    for (var key in fallback) {
      if (!Object.prototype.hasOwnProperty.call(fallback, key)) continue;
      if (obj[key] === undefined) {
        result[key] = fallback[key];
      } else if (typeof fallback[key] === 'object' && !Array.isArray(fallback[key]) && typeof obj[key] === 'object') {
        result[key] = defaults(obj[key], fallback[key]);
      } else {
        result[key] = obj[key];
      }
    }
    for (var key2 in obj) {
      if (!Object.prototype.hasOwnProperty.call(obj, key2)) continue;
      if (result[key2] === undefined) result[key2] = obj[key2];
    }
    return result;
  }

  /* =========================================================================
   * CONFIG VALIDATION
   * ========================================================================= */
  function validateConfig(config) {
    if (!config || typeof config !== 'object') return { valid: false, error: 'Configuration is missing or not a valid object.' };
    if (config.schemaVersion === undefined) return { valid: false, error: 'Configuration is missing schemaVersion.' };
    if (config.schemaVersion > 1) return { valid: false, error: 'This configuration uses a newer schema version than the engine supports.' };
    if (!config.configVersion) return { valid: false, error: 'Configuration is missing configVersion.' };
    if (!config.minEngineVersion) return { valid: false, error: 'Configuration is missing minEngineVersion.' };
    if (config.minEngineVersion) {
      var parts = String(config.minEngineVersion).split('.');
      var engParts = ENGINE_VERSION.split('.');
      for (var i = 0; i < 3; i++) {
        var cfg = parseInt(parts[i] || '0', 10);
        var eng = parseInt(engParts[i] || '0', 10);
        if (cfg > eng) return { valid: false, error: 'This configuration requires engine version ' + config.minEngineVersion + ' or later.' };
        if (cfg < eng) break;
      }
    }
    var validStatuses = ['draft', 'testing', 'published', 'review_required'];
    if (!config.status || validStatuses.indexOf(config.status) < 0) {
      return { valid: false, error: 'Configuration has an invalid or missing status.' };
    }
    var statusWarning = '';
    if (config.status === 'draft') statusWarning = 'This country configuration is a draft. Treat results as preliminary.';
    else if (config.status === 'testing') statusWarning = 'This country configuration is being tested. Treat results as preliminary.';
    else if (config.status === 'review_required') statusWarning = 'This country configuration requires further review. Treat results as preliminary.';
    if (!config.policyReviewDate) return { valid: false, error: 'Configuration is missing policyReviewDate.' };
    if (!config.country || !config.countrySlug) return { valid: false, error: 'Configuration is missing country or countrySlug.' };
    if (!config.educationLevels || !config.educationLevels.length) return { valid: false, error: 'Configuration has no education levels defined.' };
    if (!config.conversionDirections || !config.conversionDirections.length) return { valid: false, error: 'Configuration has no conversion directions defined.' };

    // Validate education levels and grading systems
    var bandIds = {};
    for (var ei = 0; ei < config.educationLevels.length; ei++) {
      var level = config.educationLevels[ei];
      if (!level.id || !level.label) return { valid: false, error: 'Education level at index ' + ei + ' is missing id or label.' };
      var quals = level.qualifications || [];
      var dls = level.degreeLevels || [];
      var qualSource = dls.length ? dls : [{ qualifications: quals }];
      for (var di = 0; di < qualSource.length; di++) {
        var dlQuals = qualSource[di].qualifications || quals;
        for (var qi = 0; qi < dlQuals.length; qi++) {
          var qual = dlQuals[qi];
          if (!qual.id || !qual.label) return { valid: false, error: 'Qualification is missing id or label.' };
          var systems = qual.gradingSystems || [];
          for (var si = 0; si < systems.length; si++) {
            var sys = systems[si];
            if (!sys.id || !sys.label) return { valid: false, error: 'Grading system is missing id or label.' };
            if (!sys.conversionBands || !sys.conversionBands.length) return { valid: false, error: 'Grading system ' + sys.id + ' has no conversion bands.' };
            for (var bi = 0; bi < sys.conversionBands.length; bi++) {
              var band = sys.conversionBands[bi];
              if (!band.id) return { valid: false, error: 'Conversion band at index ' + bi + ' in ' + sys.id + ' is missing id.' };
              if (bandIds[band.id]) return { valid: false, error: 'Duplicate band id: ' + band.id + '.' };
              bandIds[band.id] = true;
              if (band.matchValue === undefined) {
                if (band.min === undefined || band.max === undefined) return { valid: false, error: 'Band ' + band.id + ' is missing min/max or matchValue.' };
                if (band.minInclusive === undefined || band.maxInclusive === undefined) return { valid: false, error: 'Band ' + band.id + ' must specify explicit minInclusive and maxInclusive.' };
              }
              if (band.pass === undefined) return { valid: false, error: 'Band ' + band.id + ' is missing pass/fail field.' };
            }
            var specialResults = sys.specialResults || sys.excludedResults;
            if (specialResults && specialResults.length) {
              var validBehaviors = ['included', 'excluded', 'manual_review', 'unsupported'];
              for (var si2 = 0; si2 < specialResults.length; si2++) {
                var sr = specialResults[si2];
                if (sr.value === undefined) return { valid: false, error: 'Special result at index ' + si2 + ' in ' + sys.id + ' is missing value.' };
                if (!sr.label) return { valid: false, error: 'Special result ' + sr.value + ' in ' + sys.id + ' is missing label.' };
                var srBehavior = sr.behavior || 'excluded';
                if (validBehaviors.indexOf(srBehavior) < 0) {
                  return { valid: false, error: 'Special result ' + sr.value + ' in ' + sys.id + ' has invalid behavior "' + srBehavior + '". Must be one of: ' + validBehaviors.join(', ') + '.' };
                }
                if (srBehavior === 'included' && sr.usGpa == null) {
                  return { valid: false, error: 'Special result ' + sr.value + ' in ' + sys.id + ' has behavior "included" but is missing usGpa.' };
                }
              }
            }
          }
        }
      }
    }

    return { valid: true, statusWarning: statusWarning };
  }

  /* =========================================================================
   * CONFIG RESOLUTION HELPERS
   * Navigate: direction → educationLevel → qualification → gradingSystem
   * ========================================================================= */
  function getDirection(config, dirId) {
    if (!config || !config.conversionDirections) return null;
    for (var i = 0; i < config.conversionDirections.length; i++) {
      if (config.conversionDirections[i].id === dirId) return config.conversionDirections[i];
    }
    return config.conversionDirections[0];
  }

  function getEducationLevels(config) { return config ? (config.educationLevels || []) : []; }

  function getDegreeLevels(config, eduLevelId) {
    var levels = getEducationLevels(config);
    for (var i = 0; i < levels.length; i++) {
      if (levels[i].id === eduLevelId) return levels[i].degreeLevels || [];
    }
    return [];
  }

  function getQualifications(config, eduLevelId, degreeLevelId) {
    var levels = getEducationLevels(config);
    for (var i = 0; i < levels.length; i++) {
      if (levels[i].id === eduLevelId) {
        if (levels[i].degreeLevels && levels[i].degreeLevels.length) {
          var dls = levels[i].degreeLevels;
          for (var j = 0; j < dls.length; j++) {
            if (dls[j].id === degreeLevelId) return dls[j].qualifications || [];
          }
          return [];
        }
        return levels[i].qualifications || [];
      }
    }
    return [];
  }

  function getGradingSystems(config, eduLevelId, qualId, degreeLevelId) {
    var quals = getQualifications(config, eduLevelId, degreeLevelId);
    for (var i = 0; i < quals.length; i++) {
      if (quals[i].id === qualId) return quals[i].gradingSystems || [];
    }
    return [];
  }

  function getGradingSystem(config, eduLevelId, qualId, systemId, degreeLevelId) {
    var systems = getGradingSystems(config, eduLevelId, qualId, degreeLevelId);
    for (var i = 0; i < systems.length; i++) {
      if (systems[i].id === systemId) return systems[i];
    }
    return null;
  }

  function getDirectionModes(direction) {
    if (!direction || !direction.modes || !direction.modes.length) return ['quick'];
    return direction.modes;
  }

  function getResultLabels(direction) {
    if (!direction) return { primary: 'Result', primaryShort: 'Result', secondary: {} };
    return direction.resultLabels || { primary: 'Result', primaryShort: 'Result', secondary: {} };
  }

  /* =========================================================================
   * CONVERSION LOGIC — pure functions, no DOM
   * ========================================================================= */
  function findConversionBand(system, gradeValue) {
    if (!system || !system.conversionBands) return null;
    var bands = system.conversionBands;
    for (var i = 0; i < bands.length; i++) {
      var band = bands[i];
      if (band.matchValue !== undefined) {
        if (band.matchValue === gradeValue) return band;
      } else {
        var gv = typeof gradeValue === 'string' ? parseNumber(gradeValue) : gradeValue;
        if (gv === null) return null;
        var min = band.min, max = band.max;
        var minOk = band.minInclusive === false ? gv > min : gv >= min;
        var maxOk = band.maxInclusive === false ? gv < max : gv <= max;
        if (minOk && maxOk) return band;
      }
    }
    return null;
  }

  function isSpecialResult(system, gradeValue) {
    var specials = system.specialResults || system.excludedResults;
    if (!specials || !specials.length) return null;
    var gvStr = String(gradeValue).trim().replace(',', '.');
    var gvNum = parseNumber(gradeValue);
    for (var i = 0; i < specials.length; i++) {
      var sv = specials[i].value;
      if (sv === gradeValue) return specials[i];
      if (String(sv).trim().replace(',', '.') === gvStr) return specials[i];
      if (gvNum !== null && parseNumber(sv) === gvNum) return specials[i];
    }
    return null;
  }

  function validateGradeInput(system, gradeStr) {
    if (!system) return { empty: true, valid: false, error: 'Select a grading system first.' };
    var rules = system.inputRules;
    if (!rules) return { empty: true, valid: false, error: 'No input rules defined.' };

    var s = String(gradeStr).trim();
    if (!s) return { empty: true, valid: true };

    // Check configured special results BEFORE numeric validation
    var special = isSpecialResult(system, s);
    if (special) return { empty: false, valid: true, value: s, specialResult: special };

    if (rules.type === 'select') {
      var validValues = (rules.options || []).map(function (o) { return o.value; });
      if (validValues.indexOf(s) < 0) return { empty: false, valid: false, error: 'Select a valid option from the list.' };
      return { empty: false, valid: true, value: s };
    }

    // numeric — respect allowDecimalComma config (lives under inputRules)
    var allowDecimalComma = (rules.allowDecimalComma !== undefined) ? rules.allowDecimalComma : true;
    if (!allowDecimalComma && s.indexOf(',') >= 0) {
      return { empty: false, valid: false, error: 'Use a dot (.) as the decimal separator.' };
    }
    if (!isValidDecimal(s, allowDecimalComma)) return { empty: false, valid: false, error: 'Enter a valid number.' };
    var n = parseNumber(s);
    if (n === null) return { empty: false, valid: false, error: 'Enter a valid number.' };
    if (rules.min !== undefined && n < rules.min) return { empty: false, valid: false, error: 'Grade cannot be below ' + rules.min + '.' };
    if (rules.max !== undefined && n > rules.max) return { empty: false, valid: false, error: 'Grade cannot exceed ' + rules.max + '.' };
    if (rules.allowedValues && rules.allowedValues.length) {
      var matched = false;
      for (var i = 0; i < rules.allowedValues.length; i++) {
        if (Math.abs(n - rules.allowedValues[i]) < 1e-9) { matched = true; break; }
      }
      if (!matched) return { empty: false, valid: false, error: 'This grading system only accepts specific values: ' + rules.allowedValues.join(', ') + '.' };
    }
    if (rules.precision !== undefined) {
      var parts = s.replace(',', '.').split('.');
      if (parts[1] && parts[1].length > rules.precision) return { empty: false, valid: false, error: 'This grade allows at most ' + rules.precision + ' decimal place' + (rules.precision === 1 ? '' : 's') + '.' };
    }
    if (rules.step !== undefined && rules.step > 0) {
      var stepParts = String(rules.step).split('.');
      var stepDecimals = stepParts[1] ? stepParts[1].length : 0;
      var nRounded = Math.round(n / rules.step) * rules.step;
      if (stepDecimals > 0) nRounded = parseFloat(nRounded.toFixed(stepDecimals));
      else nRounded = Math.round(nRounded);
      if (Math.abs(n - nRounded) > 1e-9) return { empty: false, valid: false, error: 'This grade must be in increments of ' + rules.step + '.' };
    }
    return { empty: false, valid: true, value: n };
  }

  /* =========================================================================
   * QUICK CONVERSION CALCULATION
   * ========================================================================= */
  function calculateQuick(config, eduLevelId, qualId, systemId, gradeStr, degreeLevelId) {
    var system = getGradingSystem(config, eduLevelId, qualId, systemId, degreeLevelId);
    if (!system) return { state: 'invalid', error: 'Configuration unavailable: grading system not found.' };

    var validation = validateGradeInput(system, gradeStr);
    if (validation.empty) return { state: 'empty', system: system };
    if (!validation.valid) return { state: 'invalid', error: validation.error, system: system };

    var special = validation.specialResult || isSpecialResult(system, validation.value);
    if (special) {
      var behavior = special.behavior || 'excluded';
      if (behavior === 'included') {
        var incBand = special.conversionBand ? findConversionBand(system, special.conversionBand) : findConversionBand(system, validation.value);
        if (special.usGpa != null) {
          return {
            state: 'success', system: system, localGrade: String(gradeStr),
            localClassification: special.localClassification || (incBand ? incBand.localClassification : ''),
            pass: special.pass !== undefined ? special.pass : (incBand ? incBand.pass : true),
            usLetter: special.usLetter || (incBand ? incBand.usLetter : '\u2014'),
            usGpa: special.usGpa, band: incBand, specialResult: special,
            explanation: ''
          };
        }
      }
      if (behavior === 'manual_review') return { state: 'manual_review', system: system, localGrade: gradeStr, reason: 'special_result' };
      if (behavior === 'unsupported') return { state: 'manual_review', system: system, localGrade: gradeStr, reason: 'unsupported_result' };
      return { state: 'excluded', system: system, excludedLabel: special.label, localGrade: gradeStr };
    }

    var band = findConversionBand(system, validation.value);
    if (!band) return { state: 'manual_review', system: system, localGrade: gradeStr, reason: 'no_band' };
    if (band.manualReview) return { state: 'manual_review', system: system, localGrade: gradeStr, band: band, reason: 'band_flagged' };

    var localGradeDisplay = String(gradeStr);
    var explanation = system.explanationTemplate || '';
    explanation = explanation.replace(/\{localGrade\}/g, esc(localGradeDisplay));
    explanation = explanation.replace(/\{localClassification\}/g, esc(band.localClassification || ''));
    explanation = explanation.replace(/\{usLetter\}/g, esc(band.usLetter || ''));
    explanation = explanation.replace(/\{usGpa\}/g, esc(String(band.usGpa != null ? band.usGpa.toFixed(1) : '')));

    return {
      state: 'success', system: system, localGrade: localGradeDisplay,
      localClassification: band.localClassification, pass: band.pass,
      usLetter: band.usLetter, usGpa: band.usGpa, band: band, explanation: explanation
    };
  }

  /* =========================================================================
   * TRANSCRIPT CALCULATION — method comes from config
   * ========================================================================= */
  function calculateTranscript(config, eduLevelId, qualId, systemId, courses, degreeLevelId) {
    var system = getGradingSystem(config, eduLevelId, qualId, systemId, degreeLevelId);
    if (!system) return { state: 'invalid', error: 'Configuration unavailable.' };

    var method = system.transcriptMethod || 'convert_each_course_then_weight';
    var supportedMethods = ['convert_each_course_then_weight'];
    if (method === 'not_supported') return { state: 'not_supported', system: system };
    if (supportedMethods.indexOf(method) < 0) return { state: 'not_supported', system: system, error: 'Transcript method \u201c' + method + '\u201d is not supported by this engine.' };

    var weighting = system.weightingRules || { creditsSupported: false, equalWeightingAllowed: true };
    var manualReviewPolicy = system.manualReviewPolicy || 'block_result';
    var results = [];
    var totalPoints = 0, totalWeight = 0, includedCount = 0, excludedCount = 0, manualCount = 0, totalCredits = 0;
    var hasErrors = false;
    var anyCreditsEntered = false;

    // First pass: check if any course has credits entered
    if (weighting.creditsSupported) {
      for (var ci = 0; ci < courses.length; ci++) {
        var c = parseNumber(courses[ci].credits);
        if (c !== null && c > 0) { anyCreditsEntered = true; break; }
      }
    }

    courses.forEach(function (course) {
      var validation = validateGradeInput(system, course.grade);
      if (validation.empty) { results.push({ course: course, status: 'empty' }); return; }
      if (!validation.valid) { hasErrors = true; results.push({ course: course, status: 'error', error: validation.error }); return; }

      var special = validation.specialResult || isSpecialResult(system, validation.value);
      if (special) {
        var behavior = special.behavior || 'excluded';
        if (behavior === 'included') {
          var incPoints = special.usGpa;
          if (incPoints == null) { manualCount++; results.push({ course: course, status: 'manual' }); return; }
          var incWeight = 1;
          if (weighting.creditsSupported) {
            var incCredits = parseNumber(course.credits);
            if (incCredits !== null && incCredits <= 0) {
              hasErrors = true; results.push({ course: course, status: 'error', error: 'Credits must be greater than zero.' }); return;
            }
            if (anyCreditsEntered) {
              if (incCredits === null || incCredits <= 0) {
                hasErrors = true; results.push({ course: course, status: 'error', error: 'Enter credits for this course (other courses have credits).' }); return;
              }
              incWeight = incCredits;
              totalCredits += incCredits;
            } else if (weighting.creditsRequired) {
              if (incCredits === null || incCredits <= 0) {
                hasErrors = true; results.push({ course: course, status: 'error', error: 'Credits are required for this course.' }); return;
              }
              incWeight = incCredits;
              totalCredits += incCredits;
            }
          }
          totalPoints += incPoints * incWeight;
          totalWeight += incWeight;
          includedCount++;
          results.push({ course: course, status: 'included', band: null, specialResult: special, points: incPoints, weight: incWeight });
          return;
        }
        if (behavior === 'manual_review') { manualCount++; results.push({ course: course, status: 'manual' }); return; }
        if (behavior === 'unsupported') { manualCount++; results.push({ course: course, status: 'manual' }); return; }
        excludedCount++; results.push({ course: course, status: 'excluded', label: special.label }); return;
      }

      var band = findConversionBand(system, validation.value);
      if (!band || band.manualReview) { manualCount++; results.push({ course: course, status: 'manual', band: band }); return; }

      var points = band.usGpa;
      var weight = 1;

      if (weighting.creditsSupported) {
        var credits = parseNumber(course.credits);
        if (credits !== null && credits <= 0) {
          hasErrors = true; results.push({ course: course, status: 'error', error: 'Credits must be greater than zero.' }); return;
        }
        if (anyCreditsEntered) {
          if (credits === null || credits <= 0) {
            hasErrors = true; results.push({ course: course, status: 'error', error: 'Enter credits for this course (other courses have credits).' }); return;
          }
          weight = credits;
          totalCredits += credits;
        } else if (weighting.creditsRequired) {
          if (credits === null || credits <= 0) {
            hasErrors = true; results.push({ course: course, status: 'error', error: 'Credits are required for this course.' }); return;
          }
          weight = credits;
          totalCredits += credits;
        }
      }

      totalPoints += points * weight;
      totalWeight += weight;
      includedCount++;
      results.push({ course: course, status: 'included', band: band, points: points, weight: weight });
    });

    var gpa = totalWeight > 0 ? totalPoints / totalWeight : null;
    var weightingMode = '';
    if (weighting.creditsSupported && totalCredits > 0) weightingMode = 'Credit weighted';
    else if (weighting.equalWeightingAllowed) weightingMode = 'Equal weighted';

    var isPartial = manualCount > 0 && includedCount > 0 && manualReviewPolicy === 'partial_result_allowed';
    var isBlocked = manualCount > 0 && manualReviewPolicy === 'block_result';

    if (isBlocked) {
      return {
        state: 'manual_review', results: results, gpa: null,
        includedCount: includedCount, excludedCount: excludedCount, manualCount: manualCount,
        totalCredits: totalCredits, totalCourses: courses.length,
        weightingMode: weightingMode, system: system, hasErrors: hasErrors,
        manualReviewPolicy: manualReviewPolicy,
        isPartial: false, isBlocked: true
      };
    }

    if (hasErrors) {
      return {
        state: 'invalid', results: results, gpa: null,
        includedCount: includedCount, excludedCount: excludedCount, manualCount: manualCount,
        totalCredits: totalCredits, totalCourses: courses.length,
        weightingMode: weightingMode, system: system, hasErrors: true,
        manualReviewPolicy: manualReviewPolicy,
        isPartial: false, isBlocked: false
      };
    }

    return {
      state: 'success', results: results, gpa: gpa,
      includedCount: includedCount, excludedCount: excludedCount, manualCount: manualCount,
      totalCredits: totalCredits, totalCourses: courses.length,
      weightingMode: weightingMode, system: system, hasErrors: hasErrors,
      manualReviewPolicy: manualReviewPolicy,
      isPartial: isPartial, isBlocked: false
    };
  }

  /* =========================================================================
   * MOUNT / RENDER
   * ========================================================================= */
  function mount(host, options) {
    if (!host || host.getAttribute('data-igc-mounted') === 'true') return;
    host.setAttribute('data-igc-mounted', 'true');

    options = options || {};
    var presetCountry = options.country || host.getAttribute('data-country') || '';
    var presetConfig = options.config || null;
    var configUrl = options.configUrl || host.getAttribute('data-config-url') || '';
    var configBaseUrl = options.configBaseUrl || host.getAttribute('data-config-base') || '';
    var countriesUrl = options.countriesUrl || host.getAttribute('data-countries-url') || '';
    var presetCountries = options.countries || null;
    var countryLocked = !!(presetCountry || presetConfig);

    function resolveConfigUrl(slug) {
      if (configUrl) return configUrl;
      if (configBaseUrl) return configBaseUrl.replace(/\/$/, '') + '/' + slug + '.json';
      return 'configs/' + slug + '.json';
    }

    var state = {
      mode: 'quick',
      directionId: '',
      countrySlug: presetCountry,
      config: presetConfig,
      countries: presetCountries,
      eduLevelId: '',
      degreeLevelId: '',
      qualId: '',
      systemId: '',
      grade: '',
      courses: [{ id: uid(), name: '', grade: '', credits: '' }],
      expanded: { scale: false, breakdown: false, notes: false },
      showScale: false,
      scaleUserClosed: false,
      configError: null,
      configWarning: ''
    };
    var gradeDebounceTimer = null;
    var toastMessage = '';
    var blurred = { grade: false };
    var countrySearchHighlight = -1;

    function storageKey() { return STORAGE_KEY + '-' + (state.countrySlug || 'universal'); }

    function save() {
      var saveError = false;
      try {
        var json = JSON.stringify({
          mode: state.mode, directionId: state.directionId, countrySlug: state.countrySlug,
          eduLevelId: state.eduLevelId, degreeLevelId: state.degreeLevelId, qualId: state.qualId, systemId: state.systemId,
          grade: state.grade, courses: state.courses,
          configVersion: state.config ? state.config.configVersion : ''
        });
        localStorage.setItem(storageKey(), json);
        toastMessage = 'Saved! Your entries will reload when you return.';
      } catch (e) { saveError = true; toastMessage = 'Could not save — browser storage is unavailable.'; }
      partialRender('toast');
      setTimeout(function () { toastMessage = ''; partialRender('toast'); }, 3500);
    }

    function loadSaved() {
      try {
        var saved = JSON.parse(localStorage.getItem(storageKey()) || 'null');
        if (saved && typeof saved === 'object') {
          // Discard stale state if configVersion has changed
          var currentVersion = state.config ? state.config.configVersion : '';
          if (saved.configVersion && currentVersion && saved.configVersion !== currentVersion) {
            localStorage.removeItem(storageKey());
            return;
          }
          state.mode = saved.mode || 'quick';
          state.directionId = saved.directionId || '';
          if (saved.eduLevelId) state.eduLevelId = saved.eduLevelId;
          if (saved.degreeLevelId) state.degreeLevelId = saved.degreeLevelId;
          if (saved.qualId) state.qualId = saved.qualId;
          if (saved.systemId) state.systemId = saved.systemId;
          if (saved.grade != null) state.grade = saved.grade;
          if (Array.isArray(saved.courses) && saved.courses.length) {
            state.courses = saved.courses.filter(function (c) { return c && c.id; });
          }
        }
      } catch (e) {}
    }

    function reset() {
      var hasTranscriptData = state.mode === 'transcript' && state.courses.some(function (c) { return c.name || c.grade || c.credits; });
      if (hasTranscriptData) { if (!confirm('This will clear all your entered courses. Continue?')) return; }
      state.grade = '';
      state.showScale = false;
      state.scaleUserClosed = false;
      state.courses = [{ id: uid(), name: '', grade: '', credits: '' }];
      // Restore configured defaults
      var config = state.config;
      if (config) {
        var levels = getEducationLevels(config);
        state.eduLevelId = '';
        for (var i = 0; i < levels.length; i++) {
          if (levels[i].default) { state.eduLevelId = levels[i].id; break; }
        }
        if (!state.eduLevelId && levels.length === 1) state.eduLevelId = levels[0].id;
        var dir = getDirection(config, state.directionId);
        var modes = getDirectionModes(dir);
        if (modes.indexOf('quick') >= 0) state.mode = 'quick';
        else state.mode = modes[0];
        state.degreeLevelId = '';
        state.qualId = '';
        state.systemId = '';
        autoSelectDown();
      }
      try { localStorage.removeItem(storageKey()); } catch (e) {}
      toastMessage = 'Calculator reset to defaults.';
      render();
      setTimeout(function () { toastMessage = ''; partialRender('toast'); }, 2500);
    }

    function hasResult() {
      if (!state.config || !state.eduLevelId || !state.qualId || !state.systemId) return false;
      if (state.mode === 'quick') {
        var qcalc = calculateQuick(state.config, state.eduLevelId, state.qualId, state.systemId, state.grade, state.degreeLevelId);
        return qcalc.state === 'success';
      }
      var tcalc = calculateTranscript(state.config, state.eduLevelId, state.qualId, state.systemId, state.courses, state.degreeLevelId);
      return tcalc.state === 'success' && !tcalc.isBlocked && tcalc.includedCount > 0;
    }

    function sampleMatchesContext(sample) {
      if (!sample) return false;
      if (sample.educationLevel && sample.educationLevel !== state.eduLevelId) return false;
      if (sample.degreeLevelId && sample.degreeLevelId !== state.degreeLevelId) return false;
      if (sample.qualification && sample.qualification !== state.qualId) return false;
      if (sample.gradingSystem && sample.gradingSystem !== state.systemId) return false;
      return true;
    }

    function hasMatchingSample() {
      if (!state.config || !state.config.samples) return false;
      var samples = state.config.samples;
      var modeKey = state.mode === 'transcript' ? 'transcript' : 'quick';
      if (samples[modeKey] && sampleMatchesContext(samples[modeKey])) return true;
      return false;
    }

    function loadSample() {
      if (!state.config) return;
      var samples = state.config.samples || {};
      var modeKey = state.mode === 'transcript' ? 'transcript' : 'quick';
      var sample = null;
      if (samples[modeKey] && sampleMatchesContext(samples[modeKey])) {
        sample = samples[modeKey];
      }
      if (!sample) {
        toastMessage = 'No sample is available for the current grading system selection.';
        partialRender('toast');
        setTimeout(function () { toastMessage = ''; partialRender('toast'); }, 2800);
        return;
      }
      if (sample.transcript && sample.transcript.length && state.mode === 'transcript') {
        state.courses = sample.transcript.map(function (c) {
          return { id: uid(), name: c.name || '', grade: String(c.grade || ''), credits: String(c.credits || '') };
        });
        state.grade = '';
      } else if (sample.grade !== undefined) {
        state.grade = String(sample.grade);
      }
      state.showScale = false;
      state.scaleUserClosed = false;
      render();
    }

    function setConfig(config) {
      var validation = validateConfig(config);
      if (!validation.valid) { state.configError = validation.error; state.config = null; render(); return; }
      state.config = config;
      state.configWarning = validation.statusWarning || '';
      state.configError = null;
      state.countrySlug = config.countrySlug || '';
      var dirs = config.conversionDirections || [];
      if (dirs.length) state.directionId = dirs[0].id;
      var levels = getEducationLevels(config);
      if (levels.length === 1) {
        state.eduLevelId = levels[0].id;
      } else if (levels.length > 1) {
        var defaultLevel = null;
        for (var i = 0; i < levels.length; i++) {
          if (levels[i].default) { defaultLevel = levels[i]; break; }
        }
        if (defaultLevel) state.eduLevelId = defaultLevel.id;
      }
      var dir = getDirection(config, state.directionId);
      var modes = getDirectionModes(dir);
      if (modes.length === 1) state.mode = modes[0];
      else if (modes.indexOf('quick') >= 0) state.mode = 'quick';
      autoSelectDown();
    }

    function autoSelectDown() {
      if (!state.config) return;
      var degreeLevels = getDegreeLevels(state.config, state.eduLevelId);
      if (degreeLevels.length === 1) state.degreeLevelId = degreeLevels[0].id;
      else if (degreeLevels.length > 1) {
        var defaultDl = null;
        for (var i = 0; i < degreeLevels.length; i++) {
          if (degreeLevels[i].default) { defaultDl = degreeLevels[i]; break; }
        }
        if (defaultDl && !state.degreeLevelId) state.degreeLevelId = defaultDl.id;
        var validDl = degreeLevels.some(function (d) { return d.id === state.degreeLevelId; });
        if (!validDl) state.degreeLevelId = defaultDl ? defaultDl.id : '';
      } else if (degreeLevels.length === 0) {
        state.degreeLevelId = '';
      }
      var quals = getQualifications(state.config, state.eduLevelId, state.degreeLevelId);
      if (quals.length === 1) state.qualId = quals[0].id;
      else if (quals.length > 1) {
        var validQual = quals.some(function (q) { return q.id === state.qualId; });
        if (!validQual) state.qualId = '';
      }
      var systems = getGradingSystems(state.config, state.eduLevelId, state.qualId, state.degreeLevelId);
      if (systems.length === 1) state.systemId = systems[0].id;
      else if (systems.length > 1) {
        var validSys = systems.some(function (s) { return s.id === state.systemId; });
        if (!validSys) state.systemId = '';
      }
    }

    /* === Config loading === */
    function loadConfigFromUrl(url) {
      fetch(url)
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (config) { setConfig(config); loadSaved(); render(); })
        .catch(function () {
          state.configError = 'Could not load the configuration for this country. Please try again later.';
          render();
        });
    }

    if (presetConfig) {
      setConfig(presetConfig);
      loadSaved();
    } else if (configUrl) {
      loadConfigFromUrl(configUrl);
    } else if (presetCountry) {
      loadConfigFromUrl(resolveConfigUrl(presetCountry));
    }

    /* === Country manifest loading === */
    function loadCountries() {
      if (state.countries) { render(); return; }
      var url = countriesUrl || 'configs/countries.json';
      fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (manifest) {
          state.countries = (manifest.countries || []).filter(function (c) {
            return c.status === 'published';
          });
          render();
        })
        .catch(function () { state.countries = []; render(); });
    }

    /* === Icon helpers === */
    function infoIcon() {
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
    }
    function tipIcon() {
      return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2z"/></svg>';
    }
    function manualReviewIcon() {
      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>';
    }
    function xIcon() {
      return '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    }
    function globeIcon() {
      return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
    }
    function plusIcon() {
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    }
    function trashIcon() {
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
    }
    function lockIcon() {
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    }
    function singleGradeIcon() {
      return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M9 9h6"/><path d="M9 13h6"/><path d="M9 17h3"/></svg>';
    }
    function transcriptIcon() {
      return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>';
    }
    function universityIcon() {
      return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L1 7l11 5 11-5-11-5z"/><path d="M5 9v5c0 1.7 3.1 3 7 3s7-1.3 7-3V9"/><path d="M22 7v6"/></svg>';
    }
    function highSchoolIcon() {
      return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V9l9-6 9 6v12"/><path d="M9 21v-6h6v6"/><line x1="3" y1="12" x2="21" y2="12"/></svg>';
    }
    function chevronDownIcon() {
      return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    }

    /* === Unique ID generator for accessibility === */
    var idSeq = 0;
    function nextId(prefix) { idSeq++; return 'igc-' + prefix + '-' + idSeq; }

    /* === Render: country selector === */
    function countrySelectorHtml() {
      if (state.config) {
        if (!countryLocked) {
          return '<div class="igc-country-active">' +
            '<span class="igc-country-active-label">' + globeIcon() + esc(state.config.country) + '</span>' +
            '<button class="igc-text-button" data-action="change-country" type="button">Change</button>' +
          '</div>';
        }
        return '<div class="igc-country-active">' +
          '<span class="igc-country-active-label">' + lockIcon() + esc(state.config.country) + '</span>' +
        '</div>';
      }
      return '<div class="igc-field igc-field-anim">' +
        '<span id="' + nextId('lbl') + '">Where are your grades from?</span>' +
        '<div class="igc-country-search">' +
        '<input type="text" id="' + nextId('cs') + '" data-setting="countrySearch" placeholder="Search or select country\u2026" autocomplete="off" aria-describedby="' + nextId('cs-hint') + '">' +
        '<div class="igc-country-results" data-country-results role="listbox" aria-label="Country list"></div>' +
        '</div>' +
        '<div class="igc-hint" id="' + nextId('cs-hint') + '">' + infoIcon() + 'Select the country where your grades were awarded to load the correct grading system.</div>' +
      '</div>';
    }

    /* === Render: direction selector === */
    function directionSelectorHtml() {
      if (!state.config) return '';
      var dirs = state.config.conversionDirections || [];
      if (dirs.length <= 1) return '';
      return '<div class="igc-directions" role="tablist" aria-label="Conversion direction">' + dirs.map(function (d) {
        var active = state.directionId === d.id;
        return '<button class="igc-direction-tab' + (active ? ' igc-active' : '') + '" data-action="direction" data-direction="' + esc(d.id) + '" type="button" role="tab" aria-selected="' + active + '">' + esc(d.label) + '</button>';
      }).join('') + '</div>';
    }

    /* === Render: mode tabs === */
    function modeTabsHtml() {
      if (!state.config) return '';
      var dir = getDirection(state.config, state.directionId);
      var modes = getDirectionModes(dir);
      if (modes.length <= 1) return '';
      var labels = { quick: 'Single Grade', transcript: 'Full Transcript' };
      var icons = { quick: singleGradeIcon(), transcript: transcriptIcon() };
      return '<div class="igc-modes" role="tablist" aria-label="Conversion mode">' + modes.map(function (m) {
        var active = state.mode === m;
        return '<button class="igc-mode-tab' + (active ? ' igc-active' : '') + '" data-action="mode-' + m + '" type="button" role="tab" aria-selected="' + active + '">' + (icons[m] || '') + esc(labels[m] || m) + '</button>';
      }).join('') + '</div>';
    }

    /* === Render: selector fields === */
    function educationLevelHtml() {
      if (!state.config) return '';
      var levels = getEducationLevels(state.config);
      if (levels.length === 0) return '';
      if (levels.length === 1) { state.eduLevelId = levels[0].id; return ''; }
      if (levels.length <= 3) {
        var groupId = nextId('edu-group');
        var eduIcons = { university: universityIcon(), secondary: highSchoolIcon() };
        var buttons = levels.map(function (l) {
          var active = state.eduLevelId === l.id;
          var displayLabel = l.shortLabel || l.label;
          return '<button class="igc-edu-tab' + (active ? ' igc-active' : '') + '" data-action="edu-level" data-edu-level="' + esc(l.id) + '" type="button" role="radio" aria-checked="' + active + '" aria-label="' + esc(displayLabel) + '">' + (eduIcons[l.id] || '') + esc(displayLabel) + '</button>';
        }).join('');
        return '<div class="igc-edu-segmented" role="radiogroup" aria-label="Education Level" id="' + groupId + '">' + buttons + '</div>';
      }
      var fid = nextId('edu');
      var options = '<option value="">Select\u2026</option>' + levels.map(function (l) {
        return '<option value="' + esc(l.id) + '"' + (state.eduLevelId === l.id ? ' selected' : '') + '>' + esc(l.shortLabel || l.label) + '</option>';
      }).join('');
      return '<div class="igc-field igc-field-anim"><label for="' + fid + '">Education Level</label><select id="' + fid + '" data-setting="eduLevelId">' + options + '</select></div>';
    }

    function degreeLevelHtml() {
      if (!state.config || !state.eduLevelId) return '';
      var dls = getDegreeLevels(state.config, state.eduLevelId);
      if (dls.length === 0) return '';
      if (dls.length === 1) { state.degreeLevelId = dls[0].id; return ''; }
      if (dls.length <= 3) {
        var groupId = nextId('dl-group');
        var buttons = dls.map(function (d) {
          var active = state.degreeLevelId === d.id;
          var displayLabel = d.shortLabel || d.label;
          return '<button class="igc-edu-tab' + (active ? ' igc-active' : '') + '" data-action="degree-level" data-degree-level="' + esc(d.id) + '" type="button" role="radio" aria-checked="' + active + '" aria-label="' + esc(displayLabel) + '">' + esc(displayLabel) + '</button>';
        }).join('');
        return '<div class="igc-field igc-field-anim"><span class="igc-field-label-text">Degree Level</span><div class="igc-edu-segmented" role="radiogroup" aria-label="Degree Level" id="' + groupId + '">' + buttons + '</div></div>';
      }
      var fid = nextId('dl');
      var options = '<option value="">Select\u2026</option>' + dls.map(function (d) {
        return '<option value="' + esc(d.id) + '"' + (state.degreeLevelId === d.id ? ' selected' : '') + '>' + esc(d.shortLabel || d.label) + '</option>';
      }).join('');
      return '<div class="igc-field igc-field-anim"><label for="' + fid + '">Degree Level</label><select id="' + fid + '" data-setting="degreeLevelId">' + options + '</select></div>';
    }

    function qualificationHtml() {
      if (!state.config || !state.eduLevelId) return '';
      var quals = getQualifications(state.config, state.eduLevelId, state.degreeLevelId);
      if (quals.length === 0) return '';
      if (quals.length === 1) { state.qualId = quals[0].id; return ''; }
      var fid = nextId('qual');
      var options = '<option value="">Select\u2026</option>' + quals.map(function (q) {
        return '<option value="' + esc(q.id) + '"' + (state.qualId === q.id ? ' selected' : '') + '>' + esc(q.label) + '</option>';
      }).join('');
      return '<div class="igc-field igc-field-anim"><label for="' + fid + '">Qualification</label><select id="' + fid + '" data-setting="qualId">' + options + '</select></div>';
    }

    function gradingSystemHtml() {
      if (!state.config || !state.eduLevelId || !state.qualId) return '';
      var systems = getGradingSystems(state.config, state.eduLevelId, state.qualId, state.degreeLevelId);
      if (systems.length === 0) return '';
      if (systems.length === 1) { state.systemId = systems[0].id; return ''; }
      var fid = nextId('sys');
      var options = '<option value="">Select\u2026</option>' + systems.map(function (s) {
        return '<option value="' + esc(s.id) + '"' + (state.systemId === s.id ? ' selected' : '') + '>' + esc(s.label) + '</option>';
      }).join('');
      return '<div class="igc-field igc-field-anim"><label for="' + fid + '">Grading System</label><select id="' + fid + '" data-setting="systemId">' + options + '</select></div>';
    }

    /* === Render: grade input === */
    function gradeInputHtml() {
      if (!state.config || !state.eduLevelId || !state.qualId || !state.systemId) return '';
      var system = getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId);
      if (!system) return '';
      var rules = system.inputRules;
      if (!rules) return '';

      var validation = validateGradeInput(system, state.grade);
      var fid = nextId('grade');
      var errorId = fid + '-error';
      var errorHtml = '';
      var ariaInvalid = '';
      if (blurred.grade && !validation.empty && !validation.valid) {
        errorHtml = '<span class="igc-field-error" id="' + errorId + '" role="alert">' + esc(validation.error) + '</span>';
        ariaInvalid = ' aria-invalid="true" aria-describedby="' + errorId + '"';
      }
      var fieldClass = 'igc-field' + (errorHtml ? ' igc-field-has-error' : '');

      if (rules.type === 'select') {
        var options = '<option value="">' + esc(rules.placeholder || 'Select\u2026') + '</option>' + (rules.options || []).map(function (o) {
          return '<option value="' + esc(o.value) + '"' + (state.grade === o.value ? ' selected' : '') + '>' + esc(o.label) + '</option>';
        }).join('');
        return '<div class="' + fieldClass + ' igc-field-anim">' +
          '<label for="' + fid + '">' + esc(rules.label) + '</label>' +
          '<select id="' + fid + '" data-setting="grade"' + ariaInvalid + '>' + options + '</select>' +
          (rules.helpText ? '<div class="igc-hint">' + infoIcon() + esc(rules.helpText) + '</div>' : '') +
          errorHtml + '</div>';
      }

      var inputAttrs = 'data-setting="grade" data-numeric type="text" inputmode="decimal"';
      if (rules.step !== undefined) inputAttrs += ' step="' + rules.step + '"';
      var placeholder = rules.placeholder || '';
      var gradeInputHtml = '<div class="' + fieldClass + ' igc-field-anim">' +
        '<label for="' + fid + '">' + esc(rules.label) + '</label>' +
        '<input id="' + fid + '" ' + inputAttrs + ' placeholder="' + esc(placeholder) + '" value="' + esc(state.grade) + '"' + ariaInvalid + '>' +
        (rules.helpText ? '<div class="igc-hint">' + infoIcon() + esc(rules.helpText) + '</div>' : '') +
        errorHtml + '</div>';
      var specials = system.specialResults || system.excludedResults;
      if (specials && specials.length) {
        var sid = nextId('special');
        var specialOpts = '<option value="">Regular grade\u2026</option>' + specials.map(function (r) {
          return '<option value="' + esc(r.value) + '"' + (state.grade === r.value ? ' selected' : '') + '>' + esc(r.label) + '</option>';
        }).join('');
        gradeInputHtml += '<div class="igc-field igc-special-result-field"><label for="' + sid + '">Or select a special result</label><select id="' + sid + '" data-setting="grade">' + specialOpts + '</select></div>';
      }
      return gradeInputHtml;
    }

    /* === Render: warnings (from edu level / qualification / grading system) === */
    function warningsHtml() {
      if (!state.config || !state.eduLevelId) return '';
      var warnings = [];
      var levels = getEducationLevels(state.config);
      for (var i = 0; i < levels.length; i++) {
        if (levels[i].id === state.eduLevelId) {
          if (levels[i].warnings) warnings = warnings.concat(levels[i].warnings);
          break;
        }
      }
      var quals = getQualifications(state.config, state.eduLevelId, state.degreeLevelId);
      for (var j = 0; j < quals.length; j++) {
        if (quals[j].id === state.qualId && quals[j].warnings) warnings = warnings.concat(quals[j].warnings);
      }
      if (state.systemId) {
        var system = getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId);
        if (system && system.warnings) warnings = warnings.concat(system.warnings);
      }
      if (!warnings.length) return '';
      return '<div class="igc-warnings">' + warnings.map(function (w) {
        return '<div class="igc-tip-banner"><span class="igc-tip-icon">' + tipIcon() + '</span><span class="igc-tip-text">' + esc(w) + '</span></div>';
      }).join('') + '</div>';
    }

    /* === Render: quick result === */
    function quickResultHtml() {
      if (!state.config || !state.eduLevelId || !state.qualId || !state.systemId) return '';
      var dir = getDirection(state.config, state.directionId);
      var labels = getResultLabels(dir);
      var calc = calculateQuick(state.config, state.eduLevelId, state.qualId, state.systemId, state.grade, state.degreeLevelId);
      var primaryLabel = labels.primary || 'Est US GPA';

      if (calc.state === 'empty') {
        return '';
      }
      if (calc.state === 'invalid') {
        return '<section class="igc-results" aria-live="polite"><div class="igc-result-primary igc-result-empty"><div><p class="igc-eyebrow">' + esc(primaryLabel) + '</p><strong>\u2014</strong><span>' + esc(calc.error) + '</span></div></div></section>';
      }
      if (calc.state === 'manual_review') {
        return '<section class="igc-results" aria-live="polite"><div class="igc-result-manual"><div><p class="igc-eyebrow">' + manualReviewIcon() + ' Manual Review Needed</p><strong>Manual Review Needed</strong><p>We couldn\u2019t safely convert this result using the selected grading system. Check the grading legend on your transcript or the requirements of the institution reviewing your credentials.</p></div></div></section>';
      }
      if (calc.state === 'excluded') {
        return '<section class="igc-results" aria-live="polite"><div class="igc-result-manual" style="background: linear-gradient(135deg, #475569 0%, #64748b 100%);"><div><p class="igc-eyebrow">' + xIcon() + ' Excluded Result</p><strong>' + esc(calc.excludedLabel) + '</strong><p>This result type is excluded from GPA conversion and does not count toward your estimated result.</p></div></div></section>';
      }

      var gpaDisplay = formatGpa(calc.usGpa);
      var sec = labels.secondary || {};
      var detailChips = '';
      if (calc.usLetter && sec.usLetter) detailChips += '<span class="igc-result-detail"><span class="igc-result-detail-label">' + esc(sec.usLetter) + '</span><span class="igc-result-detail-value">' + esc(calc.usLetter) + '</span></span>';
      if (calc.localGrade && sec.localGrade) detailChips += '<span class="igc-result-detail"><span class="igc-result-detail-label">' + esc(sec.localGrade) + '</span><span class="igc-result-detail-value">' + esc(calc.localGrade) + '</span></span>';
      if (calc.localClassification && sec.localClassification) detailChips += '<span class="igc-result-detail"><span class="igc-result-detail-label">' + esc(sec.localClassification) + '</span><span class="igc-result-detail-value">' + esc(calc.localClassification) + '</span></span>';
      if (calc.pass === false) detailChips += '<span class="igc-result-detail"><span class="igc-result-detail-label">Status</span><span class="igc-result-detail-value" style="color:#fca5a5;">Non-passing</span></span>';

      return '<section class="igc-results" aria-live="polite">' +
        '<div class="igc-result-primary"><div class="igc-result-main"><p class="igc-eyebrow">' + esc(primaryLabel) + '</p>' +
        '<strong>' + gpaDisplay + '</strong></div>' +
        (detailChips ? '<div class="igc-result-details">' + detailChips + '</div>' : '') + '</div>' +
        (calc.explanation ? '<div class="igc-explanation">' + calc.explanation + '</div>' : '') +
      '</section>';
    }

    /* === Render: transcript builder === */
    function transcriptHtml() {
      if (!state.config || !state.eduLevelId || !state.qualId || !state.systemId) return '';
      var system = getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId);
      if (!system) return '';
      if (system.transcriptMethod === 'not_supported') {
        return '<div class="igc-transcript-info">' + infoIcon() + '<span>Full transcript conversion is not supported for this grading system. Use Quick Conversion instead.</span></div>';
      }
      var weighting = system.weightingRules || {};
      var showCredits = weighting.creditsSupported;
      var creditLabel = weighting.creditLabel || 'Credits';

      var infoBanner = '';
      if (showCredits && weighting.creditsRequired) {
        infoBanner = '<div class="igc-transcript-info">' + infoIcon() + '<span>Credits are required for each course. Enter the ' + esc(creditLabel) + ' shown on your transcript.</span></div>';
      } else if (showCredits && !weighting.creditsRequired) {
        infoBanner = '<div class="igc-transcript-info">' + infoIcon() + '<span>Credits are optional. If omitted, courses will be calculated using equal weighting.</span></div>';
      } else if (!showCredits && weighting.equalWeightingAllowed) {
        infoBanner = '<div class="igc-transcript-info">' + infoIcon() + '<span>Calculated using equal course weighting \u2014 credits are not used in this grading system.</span></div>';
      }

      var courseRows = state.courses.map(function (course, idx) {
        return courseRowHtml(course, idx, system, showCredits, creditLabel);
      }).join('');

      return '<div class="igc-transcript">' + infoBanner +
        '<div class="igc-course-list">' + courseRows + '</div>' +
        '<button class="igc-text-button igc-add-course" data-action="add-course" type="button">' + plusIcon() + ' Add Course</button></div>';
    }

    function courseRowHtml(course, idx, system, showCredits, creditLabel) {
      var validation = validateGradeInput(system, course.grade);
      var hasError = course.grade && !validation.empty && !validation.valid;
      var status = '', statusClass = '';

      if (course.grade && validation.valid && !validation.empty) {
        var special = isSpecialResult(system, validation.value);
        var band = findConversionBand(system, validation.value);
        if (special) {
          var beh = special.behavior || 'excluded';
          if (beh === 'included') {
            status = 'Included \u2192 ' + (special.usLetter || '\u2014') + ' (' + formatGpa(special.usGpa) + ')';
            statusClass = 'igc-status-included';
          } else if (beh === 'excluded') { status = 'Excluded: ' + special.label; statusClass = 'igc-status-excluded'; }
          else { status = 'Manual Review Needed'; statusClass = 'igc-status-manual'; }
        }
        else if (!band || band.manualReview) { status = 'Manual Review Needed'; statusClass = 'igc-status-manual'; }
        else { status = 'Included \u2192 ' + band.usLetter + ' (' + formatGpa(band.usGpa) + ')'; statusClass = 'igc-status-included'; }
      }

      var errorHtml = hasError ? '<div class="igc-course-error">' + esc(validation.error) + '</div>' : '';
      var statusHtml = status ? '<div class="igc-course-status ' + statusClass + '">' + esc(status) + '</div>' : '';
      var nameId = nextId('cn-' + idx);
      var gradeId = nextId('cg-' + idx);
      var creditsId = nextId('cc-' + idx);

      var desktopRow = '<div class="igc-course-grid' + (showCredits ? '' : ' igc-no-credits') + '">' +
        '<input data-field="name" data-course="' + idx + '" value="' + esc(course.name) + '" placeholder="Course name (optional)" aria-label="Course name" id="' + nameId + '">' +
        (showCredits ? '<input data-field="credits" data-course="' + idx + '" data-numeric value="' + esc(course.credits) + '" placeholder="' + esc(creditLabel) + '" aria-label="' + esc(creditLabel) + '" id="' + creditsId + '">' : '') +
        gradeInputForCourse(course, idx, system, gradeId) +
        '<button class="igc-icon-button" data-action="remove-course" data-course="' + idx + '" type="button" aria-label="Remove course">' + trashIcon() + '</button></div>';

      var mobileRow = '<div class="igc-course-mobile">' +
        '<div class="igc-course-mobile-field"><label for="' + nameId + '-m">Course name (optional)</label><input data-field="name" data-course="' + idx + '" value="' + esc(course.name) + '" placeholder="Course name" id="' + nameId + '-m"></div>' +
        '<div class="igc-course-mobile-field"><label for="' + gradeId + '-m">Grade</label>' + gradeInputForCourse(course, idx, system, gradeId + '-m') + '</div>' +
        (showCredits ? '<div class="igc-course-mobile-field"><label for="' + creditsId + '-m">' + esc(creditLabel) + '</label><input data-field="credits" data-course="' + idx + '" data-numeric value="' + esc(course.credits) + '" placeholder="' + esc(creditLabel) + '" id="' + creditsId + '-m"></div>' : '') +
        '<div class="igc-course-mobile-actions">' + statusHtml +
        '<button class="igc-mobile-remove" data-action="remove-course" data-course="' + idx + '" type="button">' + trashIcon() + ' Remove</button></div></div>';

      return '<div class="igc-course' + (hasError ? ' igc-course-has-error' : '') + '" data-course-idx="' + idx + '">' + desktopRow + mobileRow + errorHtml + '</div>';
    }

    function gradeInputForCourse(course, idx, system, fieldId) {
      var rules = system.inputRules;
      if (!rules) return '<input data-field="grade" data-course="' + idx + '" placeholder="Grade" id="' + fieldId + '">';
      if (rules.type === 'select') {
        var options = '<option value="">' + esc(rules.placeholder || 'Select\u2026') + '</option>' + (rules.options || []).map(function (o) {
          return '<option value="' + esc(o.value) + '"' + (course.grade === o.value ? ' selected' : '') + '>' + esc(o.label) + '</option>';
        }).join('');
        return '<select data-field="grade" data-course="' + idx + '" aria-label="Grade" id="' + fieldId + '">' + options + '</select>';
      }
      var numericInput = '<input data-field="grade" data-course="' + idx + '" data-numeric type="text" inputmode="decimal" placeholder="' + esc(rules.placeholder || 'Grade') + '" value="' + esc(course.grade) + '" aria-label="Grade" id="' + fieldId + '">';
      var courseSpecials = system.specialResults || system.excludedResults;
      if (courseSpecials && courseSpecials.length) {
        var specialOpts = '<option value="">Regular grade\u2026</option>' + courseSpecials.map(function (r) {
          return '<option value="' + esc(r.value) + '"' + (course.grade === r.value ? ' selected' : '') + '>' + esc(r.label) + '</option>';
        }).join('');
        return '<div class="igc-grade-special-wrap">' + numericInput + '<select data-field="grade" data-course="' + idx + '" aria-label="Special result" class="igc-special-select" id="' + fieldId + '-special">' + specialOpts + '</select></div>';
      }
      return numericInput;
    }

    /* === Render: transcript result === */
    function transcriptResultHtml() {
      if (!state.config || !state.eduLevelId || !state.qualId || !state.systemId) return '';
      var dir = getDirection(state.config, state.directionId);
      var labels = getResultLabels(dir);
      var primaryLabel = labels.primary || 'Est US GPA';
      var calc = calculateTranscript(state.config, state.eduLevelId, state.qualId, state.systemId, state.courses, state.degreeLevelId);

      if (calc.hasErrors) {
        return '<section class="igc-results" aria-live="polite"><div class="igc-result-primary igc-result-empty"><div><p class="igc-eyebrow">' + esc(primaryLabel) + '</p><strong>\u2014</strong><span>Fix errors in your courses above to calculate</span></div></div></section>';
      }
      if (calc.state === 'invalid' || calc.state === 'not_supported') return '';
      if (calc.state === 'manual_review' || (calc.isBlocked && calc.manualCount > 0)) {
        if (calc.manualCount > 0 && (calc.includedCount > 0 || calc.state === 'manual_review')) {
          return '<section class="igc-results" aria-live="polite"><div class="igc-result-manual"><div><p class="igc-eyebrow">' + manualReviewIcon() + ' Manual Review Needed</p><strong>Manual Review Needed</strong><p>' + calc.manualCount + ' course' + (calc.manualCount > 1 ? 's' : '') + ' require manual review. A complete GPA cannot be calculated until those grades are resolved.</p></div></div></section>';
        }
      }
      if (calc.includedCount === 0) {
        if (calc.manualCount > 0) {
          return '<section class="igc-results" aria-live="polite"><div class="igc-result-manual"><div><p class="igc-eyebrow">' + manualReviewIcon() + ' Manual Review Needed</p><strong>Manual Review Needed</strong><p>One or more courses need manual review before a reliable conversion can be produced.</p></div></div></section>';
        }
        return '<section class="igc-results" aria-live="polite"><div class="igc-result-primary igc-result-empty"><div><p class="igc-eyebrow">' + esc(primaryLabel) + '</p><strong>\u2014</strong><span>Add at least one course with a valid grade</span></div></div></section>';
      }

      var gpaDisplay = formatGpa(calc.gpa);
      var isPartial = calc.isPartial;
      var resultPrefix = isPartial ? '<span class="igc-partial-flag">Partial estimate</span>' : '';
      var resultSubText = isPartial ? 'Partial estimate based on ' + calc.includedCount + ' of ' + calc.totalCourses + ' courses' : calc.totalCourses + ' courses converted';
      var summaryPills = '';
      summaryPills += '<span class="igc-summary-pill"><strong>' + calc.includedCount + '</strong> included</span>';
      if (calc.excludedCount > 0) summaryPills += '<span class="igc-summary-pill"><strong>' + calc.excludedCount + '</strong> excluded</span>';
      if (calc.manualCount > 0) summaryPills += '<span class="igc-summary-pill"><strong>' + calc.manualCount + '</strong> manual review</span>';
      if (calc.totalCredits > 0) summaryPills += '<span class="igc-summary-pill"><strong>' + calc.totalCredits + '</strong> credits</span>';
      summaryPills += '<span class="igc-summary-pill">' + esc(calc.weightingMode) + '</span>';

      return '<section class="igc-results" aria-live="polite">' +
        '<div class="igc-result-primary"><div><p class="igc-eyebrow">' + esc(primaryLabel) + '</p>' +
        '<strong>' + gpaDisplay + '</strong>' +
        '<span class="igc-result-sub">' + resultPrefix + resultSubText + '</span></div>' +
        '<div class="igc-result-badge">' + esc(calc.system.label) + '</div></div>' +
        '<div class="igc-transcript-summary">' + summaryPills + '</div></section>';
    }

    /* === Render: full scale viewer (config-driven, collapsible) === */
    function fullScaleHtml() {
      if (!state.config || !state.eduLevelId || !state.qualId || !state.systemId) return '';
      // Auto-open scale when a result exists, unless the user explicitly closed it
      var shouldShow = state.showScale || (hasResult() && !state.scaleUserClosed);
      if (!shouldShow) {
        return '<div class="igc-scale-trigger-wrap"><button class="igc-text-button igc-scale-trigger" data-action="toggle-scale" type="button" aria-expanded="false" aria-controls="igc-scale-content">' + chevronDownIcon() + ' View Full Grade Scale</button></div>';
      }
      var system = getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId);
      if (!system || !system.conversionBands) return '';
      var bands = system.conversionBands;
      var title = system.fullScaleTitle || (state.config.fullScale && state.config.fullScale.title) || 'Full Grade Conversion Scale';

      var matchedBand = null;
      if (state.mode === 'quick' && state.grade) {
        var qcalc = calculateQuick(state.config, state.eduLevelId, state.qualId, state.systemId, state.grade, state.degreeLevelId);
        if (qcalc.state === 'success' && qcalc.band) matchedBand = qcalc.band;
      } else if (state.mode === 'transcript') {
        var tcalc = calculateTranscript(state.config, state.eduLevelId, state.qualId, state.systemId, state.courses, state.degreeLevelId);
        if (tcalc.state === 'success' && !tcalc.isBlocked && tcalc.includedCount > 0) {
          var calcGpa = tcalc.gpa;
          var bestBand = null;
          var bestDiff = Infinity;
          for (var i = 0; i < bands.length; i++) {
            if (bands[i].usGpa == null) continue;
            var diff = Math.abs(bands[i].usGpa - calcGpa);
            if (diff < bestDiff) { bestDiff = diff; bestBand = bands[i]; }
          }
          matchedBand = bestBand;
        }
      }

      function bandMatches(b) {
        if (!matchedBand) return false;
        if (b.matchValue !== undefined && matchedBand.matchValue !== undefined) return b.matchValue === matchedBand.matchValue;
        if (b.min !== undefined && matchedBand.min !== undefined) return b.min === matchedBand.min && b.max === matchedBand.max;
        return false;
      }

      var tableRows = bands.map(function (b) {
        var localGrade = b.matchValue !== undefined ? b.matchValue : (b.min === b.max ? String(b.min) : b.min + '\u2013' + b.max);
        var passClass = b.pass ? 'igc-scale-pass' : 'igc-scale-fail';
        var highlightClass = bandMatches(b) ? ' igc-scale-row-highlighted' : '';
        return '<tr class="' + highlightClass.trim() + '"><td><strong>' + esc(localGrade) + '</strong></td><td>' + esc(b.localClassification || '') + '</td><td class="' + passClass + '">' + (b.pass ? 'Pass' : 'Fail') + '</td><td>' + esc(b.usLetter || '\u2014') + '</td><td class="igc-scale-gpa">' + (b.usGpa != null ? formatGpa(b.usGpa) : '\u2014') + '</td></tr>';
      }).join('');

      var cardRows = bands.map(function (b) {
        var localGrade = b.matchValue !== undefined ? b.matchValue : (b.min === b.max ? String(b.min) : b.min + '\u2013' + b.max);
        var passText = b.pass ? 'Pass' : 'Fail';
        var highlightClass = bandMatches(b) ? ' igc-scale-card-highlighted' : '';
        return '<div class="igc-scale-card' + highlightClass + '"><div class="igc-scale-card-header">' + esc(localGrade) + ' \u2014 ' + esc(b.localClassification || '') + '</div><div class="igc-scale-card-row"><span>Pass/Fail</span><span style="color: ' + (b.pass ? 'var(--igc-success)' : 'var(--igc-error)') + '">' + passText + '</span></div><div class="igc-scale-card-row"><span>US Letter Grade</span><span>' + esc(b.usLetter || '\u2014') + '</span></div><div class="igc-scale-card-row"><span>US GPA</span><span style="color: var(--igc-primary);">' + (b.usGpa != null ? formatGpa(b.usGpa) : '\u2014') + '</span></div></div>';
      }).join('');

      return '<section class="igc-scale-section" id="igc-scale-content">' +
        '<div class="igc-scale-header"><h3 class="igc-scale-title">' + esc(title) + '</h3>' +
        '<button class="igc-text-button igc-scale-hide" data-action="toggle-scale" type="button" aria-expanded="true" aria-controls="igc-scale-content">Hide Full Grade Scale</button></div>' +
        '<table class="igc-scale-table"><thead><tr><th>Local Grade</th><th>Classification</th><th>Pass/Fail</th><th>US Letter</th><th>US GPA</th></tr></thead><tbody>' + tableRows + '</tbody></table>' +
        '<div class="igc-scale-cards">' + cardRows + '</div></section>';
    }

    /* === Render: breakdown === */
    function breakdownHtml() {
      if (!state.config || !state.eduLevelId || !state.qualId || !state.systemId) return '';
      if (state.mode === 'quick') {
        var calc = calculateQuick(state.config, state.eduLevelId, state.qualId, state.systemId, state.grade, state.degreeLevelId);
        if (calc.state !== 'success') return '';
        var levelLabel = getEducationLevels(state.config).filter(function (l) { return l.id === state.eduLevelId; })[0];
        var qualLabel = getQualifications(state.config, state.eduLevelId, state.degreeLevelId).filter(function (q) { return q.id === state.qualId; })[0];
        var rows = [
          ['Country', state.config.country],
          ['Education Level', levelLabel ? levelLabel.label : ''],
        ];
        if (state.degreeLevelId) {
          var dlLabel = getDegreeLevels(state.config, state.eduLevelId).filter(function (d) { return d.id === state.degreeLevelId; })[0];
          rows.push(['Degree Level', dlLabel ? dlLabel.label : '']);
        }
        rows.push(
          ['Qualification', qualLabel ? qualLabel.label : ''],
          ['Grading System', calc.system.label],
          ['Local Grade', calc.localGrade],
          ['Classification', calc.localClassification || '\u2014'],
          ['Matched Conversion Band', calc.band ? (calc.band.min !== undefined ? calc.band.min + '\u2013' + calc.band.max : calc.band.matchValue) : '\u2014'],
          ['Estimated US Letter Grade', calc.usLetter || '\u2014'],
          ['Estimated US GPA', formatGpa(calc.usGpa)]
        );
        var rowsHtml = rows.map(function (r) { return '<div class="igc-breakdown-row"><span class="igc-breakdown-label">' + esc(r[0]) + '</span><span class="igc-breakdown-value">' + esc(r[1]) + '</span></div>'; }).join('');
        return '<details class="igc-section-collapsible"' + (state.expanded.breakdown ? ' open' : '') + '>' +
          '<summary><div class="igc-section-heading"><h3>How This Was Calculated</h3></div><span class="igc-section-toggle">+</span></summary>' +
          '<div class="igc-section-body"><div class="igc-breakdown-list">' + rowsHtml + '</div></div></details>';
      }
      var tcalc = calculateTranscript(state.config, state.eduLevelId, state.qualId, state.systemId, state.courses, state.degreeLevelId);
      if (tcalc.state !== 'success' || tcalc.isBlocked || tcalc.includedCount === 0) return '';
      var courseRowsHtml = tcalc.results.filter(function (r) { return r.status === 'included'; }).map(function (r) {
        return '<div class="igc-breakdown-row"><span class="igc-breakdown-label">' + esc(r.course.name || 'Unnamed') + ' \u2014 ' + esc(r.course.grade) + '</span><span class="igc-breakdown-value">' + esc((r.band ? r.band.usLetter : null) || (r.specialResult ? r.specialResult.usLetter : null) || '\u2014') + ' (' + formatGpa(r.points) + ') \u00d7 ' + r.weight + '</span></div>';
      }).join('');
      var finalRow = '<div class="igc-breakdown-row"><span class="igc-breakdown-label">Final: total points \u00f7 total weight</span><span class="igc-breakdown-value">' + formatGpa(tcalc.gpa) + '</span></div>';
      return '<details class="igc-section-collapsible"' + (state.expanded.breakdown ? ' open' : '') + '>' +
        '<summary><div class="igc-section-heading"><h3>See Calculation Breakdown</h3></div><span class="igc-section-toggle">+</span></summary>' +
        '<div class="igc-section-body"><div class="igc-breakdown-list">' + courseRowsHtml + finalRow + '</div></div></details>';
    }

    /* === Render: notes (from grading system config) === */
    function notesHtml() {
      if (!state.config || !state.systemId) return '';
      var system = getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId);
      if (!system) return '';
      var items = [];
      // Direction note from the grading system config
      if (system.gradeDirection === 'lower_is_better') items.push('Lower grades are better on this scale.');
      else if (system.gradeDirection === 'higher_is_better') items.push('Higher grades are better on this scale.');
      // Country-specific source notes from config
      if (system.sourceNotes) items.push(esc(system.sourceNotes));
      // Config status warning if present
      if (state.configWarning) items.push('<strong>' + esc(state.configWarning) + '</strong>');
      // Estimate disclaimer — one concise line
      items.push('Estimated US GPA for comparison only \u2014 not an official credential evaluation.');
      var itemsHtml = items.map(function (n) { return '<div class="igc-notes-item">' + n + '</div>'; }).join('');
      return '<div class="igc-notes"><p class="igc-notes-title">' + infoIcon() + ' Notes</p><div class="igc-notes-list">' + itemsHtml + '</div></div>';
    }

    /* === Render: footer === */
    function footerHtml() {
      if (!state.config) return '';
      var hasResult = false;
      if (state.mode === 'quick') {
        var qcalc = calculateQuick(state.config, state.eduLevelId, state.qualId, state.systemId, state.grade, state.degreeLevelId);
        hasResult = qcalc.state === 'success';
      } else {
        var tcalc = calculateTranscript(state.config, state.eduLevelId, state.qualId, state.systemId, state.courses, state.degreeLevelId);
        hasResult = tcalc.state === 'success' && !tcalc.isBlocked && tcalc.includedCount > 0;
      }
      if (!hasResult) return '';
      return '<footer class="igc-footer-actions">' +
        '<div class="igc-footer-group igc-footer-save"><button class="igc-button igc-button-primary" data-action="save" type="button">Save on this device</button></div>' +
        '<div class="igc-footer-group igc-footer-exports">' +
        '<button class="igc-button igc-button-outline" data-action="print" type="button">Print / Save PDF</button>' +
        (state.mode === 'transcript' ? '<button class="igc-button igc-button-outline" data-action="csv" type="button">Download CSV</button>' : '') +
        '<button class="igc-button igc-button-quiet" data-action="copy" type="button">Copy Result Summary</button></div>' +
        '<div class="igc-footer-group igc-footer-clear"><button class="igc-clear" data-action="reset" type="button">Reset Calculator</button></div></footer>';
    }

    /* === Render: unavailable state === */
    function unavailableHtml() {
      return '<div class="igc-app igc-unavailable">' +
        '<div class="igc-unavailable-body">' +
        '<h2>Configuration Unavailable</h2>' +
        '<p>' + esc(state.configError || 'The configuration for this country could not be loaded.') + '</p>' +
        '<p class="igc-unavailable-hint">This may be because the country configuration has not been published yet, is undergoing review, or requires a newer version of the calculator engine.</p>' +
        '</div></div>';
    }

    /* === Main render (full) === */
    function render() {
      if (state.configError) { host.innerHTML = unavailableHtml(); return; }

      if (!state.config) {
        if (!state.countries && !countryLocked) { loadCountries(); }
        host.innerHTML = '<div class="igc-app">' +
          '<header class="igc-header"><div><h2>International Grade Converter</h2></div></header>' +
          '<div class="igc-selectors">' + countrySelectorHtml() + '</div>' +
          '<div class="igc-print-branding">Calculated by <a href="https://gpacalculator.net">GPACalculator.net</a></div></div>';
        if (!countryLocked) setupCountrySearch();
        return;
      }

      autoSelectDown();
      var pageTitle = state.config.pageTitle || (state.config.country ? state.config.country + ' Grade Converter' : 'International Grade Converter');
      var dir = getDirection(state.config, state.directionId);
      var modes = getDirectionModes(dir);
      if (modes.indexOf(state.mode) < 0) state.mode = modes[0];

      var selectorsHtml = '';
      if (!countryLocked) selectorsHtml += countrySelectorHtml();
      selectorsHtml += degreeLevelHtml() + qualificationHtml() + gradingSystemHtml();

      var eduLevelHtml = educationLevelHtml();
      var modeHtml = modeTabsHtml();
      var dualControlsHtml = '';
      if (modeHtml || eduLevelHtml) {
        dualControlsHtml = '<div class="igc-dual-controls">' +
          '<div class="igc-dual-control-item">' + modeHtml + '</div>' +
          '<div class="igc-dual-control-item">' + eduLevelHtml + '</div>' +
        '</div>';
      }

      var gradeInput = state.mode === 'quick' ? gradeInputHtml() : '';
      var transcript = state.mode === 'transcript' ? transcriptHtml() : '';
      var results = state.mode === 'quick' ? quickResultHtml() : transcriptResultHtml();
      var scaleSection = fullScaleHtml();
      var breakdownSection = breakdownHtml();
      var notesSection = notesHtml();
      var toastHtml = toastMessage ? '<div class="igc-toast" role="status">' + esc(toastMessage) + '</div>' : '';

      host.innerHTML = '<div class="igc-app">' +
        '<header class="igc-header"><div><h2>' + esc(pageTitle) + '</h2></div>' +
        '<button class="igc-button igc-button-soft" data-action="sample" type="button"' + (hasMatchingSample() ? '' : ' disabled aria-disabled="true" title="No sample available for the current selection"') + '>Try a sample</button></header>' +
        (state.configWarning ? '<div class="igc-warnings"><div class="igc-tip-banner"><span class="igc-tip-icon">' + tipIcon() + '</span><span class="igc-tip-text">' + esc(state.configWarning) + '</span></div></div>' : '') +
        dualControlsHtml + directionSelectorHtml() +
        '<div class="igc-selectors">' + selectorsHtml + '</div>' +
        warningsHtml() +
        '<div class="igc-grade-input-area">' + gradeInput + '</div>' +
        transcript + results + scaleSection + notesSection + breakdownSection + footerHtml() + toastHtml +
        '<div class="igc-print-branding">Calculated by <a href="' + esc(state.config.sourceLink || 'https://gpacalculator.net') + '">GPACalculator.net</a></div></div>';
    }

    /* === Partial render (for typed input — no full innerHTML rebuild) === */
    function partialRender(zone) {
      if (zone === 'toast') {
        var existing = host.querySelector('.igc-toast');
        if (toastMessage && !existing) {
          var toast = document.createElement('div');
          toast.className = 'igc-toast';
          toast.setAttribute('role', 'status');
          toast.textContent = toastMessage;
          host.querySelector('.igc-app').appendChild(toast);
        } else if (!toastMessage && existing) {
          existing.remove();
        } else if (toastMessage && existing) {
          existing.textContent = toastMessage;
        }
        return;
      }
      if (zone === 'results') {
        var resultsEl = host.querySelector('.igc-results');
        var newResults = state.mode === 'quick' ? quickResultHtml() : transcriptResultHtml();
        if (resultsEl && newResults) {
          var tmp = document.createElement('div');
          tmp.innerHTML = newResults;
          resultsEl.replaceWith(tmp.firstElementChild);
        } else if (resultsEl && !newResults) {
          resultsEl.remove();
        } else if (!resultsEl && newResults) {
          var insertAfter = host.querySelector('.igc-transcript') || host.querySelector('.igc-grade-input-area');
          if (insertAfter) {
            var tmp2 = document.createElement('div');
            tmp2.innerHTML = newResults;
            insertAfter.insertAdjacentElement('afterend', tmp2.firstElementChild);
          }
        }
        // Also update scale, breakdown, and footer
        partialRender('scale');
        partialRender('breakdown');
        partialRender('footer');
        return;
      }
      if (zone === 'scale') {
        var scaleEl = host.querySelector('.igc-scale-section');
        var scaleTrigger = host.querySelector('.igc-scale-trigger-wrap');
        var newScale = fullScaleHtml();
        var tmp3 = document.createElement('div');
        tmp3.innerHTML = newScale;
        var newEl = tmp3.firstElementChild;
        if (scaleEl) { if (newEl && newEl.classList.contains('igc-scale-section')) scaleEl.replaceWith(newEl); else scaleEl.remove(); }
        else if (scaleTrigger) { if (newEl) scaleTrigger.replaceWith(newEl); }
        else if (newEl) {
          var notesEl = host.querySelector('.igc-notes');
          if (notesEl) notesEl.insertAdjacentElement('beforebegin', newEl);
          else {
            var bdEl2 = host.querySelector('.igc-section-collapsible');
            if (bdEl2) bdEl2.insertAdjacentElement('beforebegin', newEl);
          }
        }
        return;
      }
      if (zone === 'breakdown') {
        var bdEl = host.querySelector('.igc-section-collapsible');
        var newBd = breakdownHtml();
        if (bdEl && newBd) { var tmp4 = document.createElement('div'); tmp4.innerHTML = newBd; bdEl.replaceWith(tmp4.firstElementChild); }
        else if (bdEl && !newBd) { bdEl.remove(); }
        else if (!bdEl && newBd) {
          var tmp5 = document.createElement('div'); tmp5.innerHTML = newBd;
          var footer = host.querySelector('.igc-footer-actions');
          if (footer) footer.insertAdjacentElement('beforebegin', tmp5.firstElementChild);
        }
        return;
      }
      if (zone === 'footer') {
        var footerEl = host.querySelector('.igc-footer-actions');
        var newFooter = footerHtml();
        if (footerEl && newFooter) {
          var tmpF = document.createElement('div'); tmpF.innerHTML = newFooter;
          footerEl.replaceWith(tmpF.firstElementChild);
        } else if (footerEl && !newFooter) {
          footerEl.remove();
        } else if (!footerEl && newFooter) {
          var tmpF2 = document.createElement('div'); tmpF2.innerHTML = newFooter;
          var branding = host.querySelector('.igc-print-branding');
          if (branding) branding.insertAdjacentElement('beforebegin', tmpF2.firstElementChild);
        }
        return;
      }
      if (zone === 'field-validation') {
        if (!state.config || !state.systemId) return;
        var system = getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId);
        if (!system) return;
        var validation = validateGradeInput(system, state.grade);
        var gradeInputEl = host.querySelector('[data-setting="grade"]');
        if (!gradeInputEl) return;
        var fieldWrapper = gradeInputEl.closest('.igc-field');
        if (!fieldWrapper) return;
        var hasError = blurred.grade && !validation.empty && !validation.valid;
        var existingError = fieldWrapper.querySelector('.igc-field-error');
        if (hasError) {
          gradeInputEl.setAttribute('aria-invalid', 'true');
          var errorId = gradeInputEl.id + '-error';
          gradeInputEl.setAttribute('aria-describedby', errorId);
          if (existingError) {
            existingError.textContent = validation.error;
          } else {
            var errEl = document.createElement('span');
            errEl.className = 'igc-field-error';
            errEl.id = errorId;
            errEl.setAttribute('role', 'alert');
            errEl.textContent = validation.error;
            fieldWrapper.appendChild(errEl);
          }
          fieldWrapper.classList.add('igc-field-has-error');
        } else {
          gradeInputEl.removeAttribute('aria-invalid');
          gradeInputEl.removeAttribute('aria-describedby');
          if (existingError) existingError.remove();
          fieldWrapper.classList.remove('igc-field-has-error');
        }
        return;
      }
      if (zone === 'course-status') {
        state.courses.forEach(function (course, idx) {
          var courseEl = host.querySelector('[data-course-idx="' + idx + '"]');
          if (!courseEl) return;
          var system = getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId);
          if (!system) return;
          var validation = validateGradeInput(system, course.grade);
          var hasError = course.grade && !validation.empty && !validation.valid;
          var status = '', statusClass = '';
          if (course.grade && validation.valid && !validation.empty) {
            var special = isSpecialResult(system, validation.value);
            var band = findConversionBand(system, validation.value);
            if (special) {
              var beh = special.behavior || 'excluded';
              if (beh === 'included') {
                status = 'Included \u2192 ' + (special.usLetter || '\u2014') + ' (' + formatGpa(special.usGpa) + ')';
                statusClass = 'igc-status-included';
              } else if (beh === 'excluded') { status = 'Excluded: ' + special.label; statusClass = 'igc-status-excluded'; }
              else { status = 'Manual Review Needed'; statusClass = 'igc-status-manual'; }
            }
            else if (!band || band.manualReview) { status = 'Manual Review Needed'; statusClass = 'igc-status-manual'; }
            else { status = 'Included \u2192 ' + band.usLetter + ' (' + formatGpa(band.usGpa) + ')'; statusClass = 'igc-status-included'; }
          }
          var existingStatus = courseEl.querySelector('.igc-course-status');
          var newStatusHtml = status ? '<div class="igc-course-status ' + statusClass + '">' + esc(status) + '</div>' : '';
          if (existingStatus && newStatusHtml) { existingStatus.className = 'igc-course-status ' + statusClass; existingStatus.textContent = status; }
          else if (existingStatus && !newStatusHtml) { existingStatus.remove(); }
          else if (!existingStatus && newStatusHtml) {
            var mobileActions = courseEl.querySelector('.igc-course-mobile-actions');
            if (mobileActions) {
              var stEl = document.createElement('div');
              stEl.className = 'igc-course-status ' + statusClass;
              stEl.textContent = status;
              mobileActions.insertBefore(stEl, mobileActions.firstChild);
            }
          }
          courseEl.classList.toggle('igc-course-has-error', hasError);
          var errorEl = courseEl.querySelector('.igc-course-error');
          if (hasError) {
            if (!errorEl) { errorEl = document.createElement('div'); errorEl.className = 'igc-course-error'; courseEl.appendChild(errorEl); }
            errorEl.textContent = validation.error;
          } else if (errorEl) { errorEl.remove(); }
        });
        return;
      }
    }

    /* === Country search setup === */
    function setupCountrySearch() {
      var input = host.querySelector('[data-setting="countrySearch"]');
      var results = host.querySelector('[data-country-results]');
      if (!input || !results) return;

      function renderResults(filter) {
        var countries = state.countries || [];
        var filtered = filter ? countries.filter(function (c) { return c.name.toLowerCase().indexOf(filter.toLowerCase()) >= 0; }) : countries;
        if (!filtered.length) {
          results.innerHTML = '<div class="igc-country-option" style="color: var(--igc-muted); cursor: default;">No countries found</div>';
        } else {
          results.innerHTML = filtered.map(function (c, i) {
            return '<div class="igc-country-option" data-country-slug="' + esc(c.slug) + '" role="option" tabindex="-1">' + globeIcon() + esc(c.name) + '</div>';
          }).join('');
        }
        results.classList.add('igc-open');
        countrySearchHighlight = -1;
      }

      input.addEventListener('input', function () { renderResults(input.value); });
      input.addEventListener('focus', function () { renderResults(input.value); });

      input.addEventListener('keydown', function (e) {
        var options = results.querySelectorAll('[data-country-slug]');
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          countrySearchHighlight = Math.min(countrySearchHighlight + 1, options.length - 1);
          updateHighlight(options);
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          countrySearchHighlight = Math.max(countrySearchHighlight - 1, 0);
          updateHighlight(options);
        } else if (e.key === 'Enter') {
          e.preventDefault();
          if (countrySearchHighlight >= 0 && options[countrySearchHighlight]) {
            selectCountry(options[countrySearchHighlight]);
          }
        } else if (e.key === 'Escape') {
          results.classList.remove('igc-open');
        }
      });

      function updateHighlight(options) {
        options.forEach(function (opt, i) { opt.classList.toggle('igc-highlighted', i === countrySearchHighlight); });
        if (countrySearchHighlight >= 0 && options[countrySearchHighlight]) options[countrySearchHighlight].scrollIntoView({ block: 'nearest' });
      }

      function selectCountry(option) {
        var slug = option.getAttribute('data-country-slug');
        input.value = option.textContent.trim();
        results.classList.remove('igc-open');
        loadConfigFromUrl(resolveConfigUrl(slug));
      }

      results.addEventListener('click', function (e) {
        var option = e.target.closest('[data-country-slug]');
        if (option) selectCountry(option);
      });

      document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !results.contains(e.target)) results.classList.remove('igc-open');
      });
    }

    /* === Event handling === */
    function handleSettingChange(target) {
      var setting = target.getAttribute('data-setting');
      if (!setting || setting === 'countrySearch') return;

      if (setting === 'eduLevelId') {
        state.eduLevelId = target.value; state.degreeLevelId = ''; state.qualId = ''; state.systemId = ''; state.grade = '';
        state.courses = [{ id: uid(), name: '', grade: '', credits: '' }];
        autoSelectDown(); render();
      } else if (setting === 'degreeLevelId') {
        state.degreeLevelId = target.value; state.qualId = ''; state.systemId = ''; state.grade = '';
        state.courses = [{ id: uid(), name: '', grade: '', credits: '' }];
        autoSelectDown(); render();
      } else if (setting === 'qualId') {
        state.qualId = target.value; state.systemId = ''; state.grade = '';
        autoSelectDown(); render();
      } else if (setting === 'systemId') {
        state.systemId = target.value; state.grade = ''; render();
      } else if (setting === 'grade') {
        state.grade = target.value; blurred.grade = true;
        if (gradeDebounceTimer) clearTimeout(gradeDebounceTimer);
        gradeDebounceTimer = setTimeout(function () {
          gradeDebounceTimer = null;
          partialRender('field-validation');
          partialRender('results');
        }, 350);
        return;
      }
    }

    function handleCourseFieldChange(target) {
      var field = target.getAttribute('data-field');
      var idx = parseInt(target.getAttribute('data-course'), 10);
      if (isNaN(idx) || !state.courses[idx]) return;
      state.courses[idx][field] = target.value;
      if (field === 'grade') {
        if (gradeDebounceTimer) clearTimeout(gradeDebounceTimer);
        gradeDebounceTimer = setTimeout(function () {
          gradeDebounceTimer = null;
          partialRender('course-status');
          partialRender('results');
        }, 350);
      }
    }

    function handleAction(action, target) {
      if (action === 'mode-quick') { state.mode = 'quick'; render(); }
      else if (action === 'mode-transcript') {
        state.mode = 'transcript';
        if (state.courses.length === 0 || (state.courses.length === 1 && !state.courses[0].grade)) {
          state.courses = [{ id: uid(), name: '', grade: '', credits: '' }];
        }
        render();
      }
      else if (action === 'edu-level') {
        state.eduLevelId = target.getAttribute('data-edu-level'); state.degreeLevelId = ''; state.qualId = ''; state.systemId = ''; state.grade = '';
        state.courses = [{ id: uid(), name: '', grade: '', credits: '' }];
        autoSelectDown(); render();
      }
      else if (action === 'degree-level') {
        state.degreeLevelId = target.getAttribute('data-degree-level'); state.qualId = ''; state.systemId = ''; state.grade = '';
        state.courses = [{ id: uid(), name: '', grade: '', credits: '' }];
        autoSelectDown(); render();
      }
      else if (action === 'direction') { state.directionId = target.getAttribute('data-direction'); state.grade = ''; render(); }
      else if (action === 'change-country') {
        state.config = null; state.configError = null; state.configWarning = ''; state.eduLevelId = ''; state.degreeLevelId = ''; state.qualId = ''; state.systemId = ''; state.grade = '';
        state.courses = [{ id: uid(), name: '', grade: '', credits: '' }]; state.showScale = false; state.scaleUserClosed = false;
        render();
      }
      else if (action === 'toggle-scale') {
        var scaleVisible = state.showScale || (hasResult() && !state.scaleUserClosed);
        state.showScale = !scaleVisible;
        state.scaleUserClosed = scaleVisible;
        render(); return;
      }
      else if (action === 'sample') loadSample();
      else if (action === 'save') save();
      else if (action === 'reset') reset();
      else if (action === 'add-course') { state.courses.push({ id: uid(), name: '', grade: '', credits: '' }); render(); }
      else if (action === 'remove-course') {
        var idx = parseInt(target.getAttribute('data-course'), 10);
        if (!isNaN(idx)) {
          state.courses.splice(idx, 1);
          if (state.courses.length === 0) state.courses = [{ id: uid(), name: '', grade: '', credits: '' }];
          render();
        }
      }
      else if (action === 'print') { state.expanded.breakdown = true; render(); setTimeout(function () { window.print(); }, 100); }
      else if (action === 'csv') exportCsv();
      else if (action === 'copy') copyResult();
    }

    function exportCsv() {
      try {
        var calc = calculateTranscript(state.config, state.eduLevelId, state.qualId, state.systemId, state.courses, state.degreeLevelId);
        var rows = [['Course', 'Grade', 'Credits', 'US Letter', 'US GPA', 'Status']];
        calc.results.forEach(function (r) {
          rows.push([r.course.name || '', r.course.grade, r.course.credits, (r.band ? r.band.usLetter : null) || (r.specialResult ? r.specialResult.usLetter : '') || '', (r.band ? r.band.usGpa : null) != null ? formatGpa(r.band ? r.band.usGpa : (r.specialResult ? r.specialResult.usGpa : null)) : '', r.status]);
        });
        rows.push([]);
        rows.push(['Estimated US GPA', (calc.state === 'success' && !calc.isBlocked && calc.gpa !== null) ? formatGpa(calc.gpa) : 'Manual Review Needed']);
        rows.push(['Courses included', calc.includedCount]);
        rows.push(['Courses excluded', calc.excludedCount]);
        rows.push(['Manual review', calc.manualCount]);
        rows.push(['Weighting mode', calc.weightingMode]);
        rows.push([]);
        rows.push(['Calculated by GPACalculator.net']);
        var csv = rows.map(function (row) { return row.map(function (cell) { return '"' + csvSafe(cell).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
        download((state.config.countrySlug || 'grade') + '-transcript.csv', 'text/csv;charset=utf-8', csv);
      } catch (e) {
        toastMessage = 'Could not create CSV file.'; partialRender('toast');
        setTimeout(function () { toastMessage = ''; partialRender('toast'); }, 2800);
      }
    }

    function copyResult() {
      var summary = '';
      if (state.mode === 'quick') {
        var calc = calculateQuick(state.config, state.eduLevelId, state.qualId, state.systemId, state.grade, state.degreeLevelId);
        if (calc.state === 'success') {
          summary = state.config.country + ' grade: ' + calc.localGrade + ' (' + calc.localClassification + ')\n';
          summary += 'Est US GPA: ' + formatGpa(calc.usGpa) + '\n';
          summary += 'Estimated US Letter Grade: ' + calc.usLetter + '\n';
          summary += 'Source: GPACalculator.net';
        }
      } else {
        var tcalc = calculateTranscript(state.config, state.eduLevelId, state.qualId, state.systemId, state.courses, state.degreeLevelId);
        if (tcalc.state === 'success' && !tcalc.isBlocked && tcalc.gpa !== null) {
          summary = state.config.country + ' transcript conversion\n';
          summary += 'Est US GPA: ' + formatGpa(tcalc.gpa) + '\n';
          summary += tcalc.includedCount + ' courses included, ' + tcalc.excludedCount + ' excluded\n';
          summary += tcalc.weightingMode + '\n';
          summary += 'Source: GPACalculator.net';
        }
      }
      if (!summary) return;
      try {
        navigator.clipboard.writeText(summary).then(function () {
          toastMessage = 'Result summary copied to clipboard.'; partialRender('toast');
          setTimeout(function () { toastMessage = ''; partialRender('toast'); }, 2500);
        }).catch(function () { toastMessage = 'Could not copy to clipboard.'; partialRender('toast'); setTimeout(function () { toastMessage = ''; partialRender('toast'); }, 2500); });
      } catch (e) { toastMessage = 'Clipboard not available in this browser.'; partialRender('toast'); setTimeout(function () { toastMessage = ''; partialRender('toast'); }, 2500); }
    }

    host.addEventListener('keydown', function (e) {
      // Keyboard support for segmented controls (role=radio)
      if (e.target.getAttribute && e.target.getAttribute('role') === 'radio') {
        var group = e.target.closest('[role="radiogroup"]');
        if (!group) return;
        var radios = Array.prototype.slice.call(group.querySelectorAll('[role="radio"]'));
        var currentIdx = radios.indexOf(e.target);
        if (currentIdx < 0) return;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
          e.preventDefault();
          var nextIdx = (currentIdx + 1) % radios.length;
          radios[nextIdx].focus();
          radios[nextIdx].click();
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
          e.preventDefault();
          var prevIdx = (currentIdx - 1 + radios.length) % radios.length;
          radios[prevIdx].focus();
          radios[prevIdx].click();
        }
      }
    });

    /* === Event delegation === */
    host.addEventListener('click', function (e) {
      var actionEl = e.target.closest('[data-action]');
      if (actionEl) {
        e.preventDefault();
        if (actionEl.hasAttribute('disabled') || actionEl.getAttribute('aria-disabled') === 'true') return;
        handleAction(actionEl.getAttribute('data-action'), actionEl);
        return;
      }
    });

    host.addEventListener('change', function (e) {
      var setting = e.target.getAttribute('data-setting');
      var field = e.target.getAttribute('data-field');
      if (setting) handleSettingChange(e.target);
      else if (field) handleCourseFieldChange(e.target);
    });

    host.addEventListener('input', function (e) {
      if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'SELECT') return;
      var setting = e.target.getAttribute('data-setting');
      var field = e.target.getAttribute('data-field');
      if (setting && setting !== 'countrySearch') handleSettingChange(e.target);
      else if (field) handleCourseFieldChange(e.target);
    });

    host.addEventListener('beforeinput', function (e) {
      if (!e.target.hasAttribute || !e.target.hasAttribute('data-numeric')) return;
      var inserted = e.data;
      if (inserted === null) return;
      // Check allowDecimalComma from current grading system
      var system = state.config && state.systemId
        ? getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId)
        : null;
      var inputRules = system ? system.inputRules : null;
      var allowDecimalComma = inputRules ? (inputRules.allowDecimalComma !== undefined ? inputRules.allowDecimalComma : true) : true;
      var allowedChars = allowDecimalComma ? /^[0-9.,]*$/ : /^[0-9.]*$/;
      if (!allowedChars.test(inserted)) { e.preventDefault(); return; }
      var current = e.target.value;
      var start = e.target.selectionStart || 0;
      var end = e.target.selectionEnd || 0;
      var candidate = current.slice(0, start) + inserted + current.slice(end);
      // Normalize comma to dot for counting decimal separators
      var normalized = candidate.replace(',', '.');
      if ((normalized.match(/\./g) || []).length > 1) { e.preventDefault(); return; }
    });

    host.addEventListener('paste', function (e) {
      if (!e.target.hasAttribute || !e.target.hasAttribute('data-numeric')) return;
      var system = state.config && state.systemId
        ? getGradingSystem(state.config, state.eduLevelId, state.qualId, state.systemId, state.degreeLevelId)
        : null;
      var inputRules = system ? system.inputRules : null;
      var allowDecimalComma = inputRules ? (inputRules.allowDecimalComma !== undefined ? inputRules.allowDecimalComma : true) : true;
      e.preventDefault();
      var pasted = (e.clipboardData || window.clipboardData).getData('text');
      if (!allowDecimalComma && pasted.indexOf(',') >= 0) {
        // Comma not permitted — reject the paste entirely
        toastMessage = 'Use a dot (.) as the decimal separator.';
        partialRender('toast');
        setTimeout(function () { toastMessage = ''; partialRender('toast'); }, 2800);
        return;
      }
      var start = e.target.selectionStart || 0;
      var end = e.target.selectionEnd || 0;
      var current = e.target.value;
      var cleaned = sanitizeNumericInput(pasted, true);
      if ((cleaned.match(/\./g) || []).length > 1) {
        cleaned = cleaned.replace(/\./g, function (m, i) { return i === cleaned.indexOf('.') ? m : ''; });
      }
      var candidate = current.slice(0, start) + cleaned + current.slice(end);
      if ((candidate.match(/\./g) || []).length > 1) {
        candidate = current.slice(0, start) + cleaned.replace(/\./g, '') + current.slice(end);
      }
      e.target.value = candidate;
      var pos = start + cleaned.length;
      e.target.setSelectionRange(pos, pos);
      var setting = e.target.getAttribute('data-setting');
      var field = e.target.getAttribute('data-field');
      if (setting && setting !== 'countrySearch') handleSettingChange(e.target);
      else if (field) handleCourseFieldChange(e.target);
    });

    host.addEventListener('blur', function (e) {
      if (e.target.getAttribute && e.target.getAttribute('data-setting') === 'grade') {
        blurred.grade = true;
        partialRender('field-validation');
        partialRender('results');
      }
      if (e.target.hasAttribute && e.target.hasAttribute('data-field') && e.target.getAttribute('data-field') === 'grade') {
        partialRender('course-status');
      }
    }, true);

    host.addEventListener('toggle', function (e) {
      var detail = e.target.closest('details');
      if (!detail || !detail.classList.contains('igc-section-collapsible')) return;
      if (detail.querySelector('h3')) {
        var text = detail.querySelector('h3').textContent || '';
        if (text.indexOf('Calculated') >= 0 || text.indexOf('Breakdown') >= 0) state.expanded.breakdown = detail.open;
      }
    }, true);

    // Initial render
    if (state.config) { render(); }
    else if (!presetCountry && !presetConfig && !configUrl) { render(); }
  }

  /* =========================================================================
   * WORDPRESS IFRAME AUTO-RESIZE
   * ========================================================================= */
  function setupIframeResize() {
    if (window.parent === window) return;
    function sendHeight() {
      var height = document.documentElement.scrollHeight;
      window.parent.postMessage({ type: 'resize-iframe', height: height }, '*');
    }
    if (typeof ResizeObserver !== 'undefined') {
      var observer = new ResizeObserver(sendHeight);
      observer.observe(document.body);
    }
    sendHeight();
    window.addEventListener('load', sendHeight);
  }

  /* =========================================================================
   * PUBLIC API
   * ========================================================================= */
  window.InternationalGradeConverter = {
    mount: mount,
    calculateQuick: calculateQuick,
    calculateTranscript: calculateTranscript,
    findConversionBand: findConversionBand,
    validateConfig: validateConfig,
    engineVersion: ENGINE_VERSION
  };

  // Auto-mount
  document.querySelectorAll('[data-international-grade-converter]').forEach(function (element) {
    try { mount(element); } catch (e) { /* fail silently */ }
  });
  setupIframeResize();
}());