<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * FreeITSM's own date and time picker (GH #355) - assets/js/date-input.js, in
 * headless Chrome. No database.
 *
 * A page publishes window.DATE_FORMAT the way Tz::scriptTag() does (day-first
 * '05 Aug 2026', 24-hour clock, weeks starting on Sunday), then checks that
 * every kind of box is upgraded and shows the chosen format, that the hidden
 * box keeps the same ISO value the app has always stored, that code setting it
 * is followed, that typed dates are read in the chosen format (and bad ones
 * refused), that the events existing code listens for still fire, that boxes
 * added later are upgraded, and that data-native-date opts out.
 *
 *   php tests/date-input.php
 * Control: without date-input.js on the page the upgrade checks fail.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
$root = dirname(__DIR__);
$js = (string)file_get_contents($root . '/assets/js/date-input.js');
if (getenv('DATE_INPUT_CONTROL')) $js = '/* control: no picker */';
$html = '<!doctype html><html><head><meta charset="utf-8"></head><body>
<form id="f">
  <input type="date" id="d1" value="2026-08-05">
  <input type="datetime-local" id="dt1" value="2026-11-15T10:00">
  <input type="time" id="t1" value="14:30">
  <input type="date" id="dreq" required>
  <span data-native-date><input type="date" id="dnat" value="2026-01-02"></span>
</form>
<div id="later"></div>
<pre id="result">RUNNING</pre>
<script>
window.DATE_FORMAT = {
  dateTemplate: "DD MON YYYY", timeTemplate: "HH:mi", dayMonthTemplate: "DD MON",
  months: ["January","February","March","April","May","June","July","August","September","October","November","December"],
  monthsInDate: ["January","February","March","April","May","June","July","August","September","October","November","December"],
  monthsShort: ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
  weekdays: ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"],
  weekdaysShort: ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"],
  weekStart: "sunday",
  picker: { invalid: "Type a date like {example}, or pick one.", invalid_time: "Type a time like {example}, or pick one.", today: "Today", now: "Now", clear: "Clear" }
};
</script>
<script>' . str_replace('</script', '<\/script', $js) . '</script>
<script>
(async function () {
  const checks = [];
  const check = (label, pass, detail) => checks.push({ label, pass: !!pass, detail: detail === undefined ? null : String(detail) });
  const tick = () => new Promise(r => setTimeout(r, 30));
  const textOf = id => { const n = document.getElementById(id); const w = n.parentElement; return w && w.classList.contains("di-wrap") ? w.querySelector(".di-text") : null; };
  try {
    await tick();
    const d1 = document.getElementById("d1"), t = textOf("d1");
    check("a date box is upgraded", !!t);
    check("it shows the chosen format", t && t.value === "05 Aug 2026", t && t.value);
    check("the hidden box keeps the ISO value", d1.value === "2026-08-05", d1.value);
    check("a date-and-time box shows date and 24-hour time", textOf("dt1") && textOf("dt1").value === "15 Nov 2026 10:00", textOf("dt1") && textOf("dt1").value);
    check("a time box shows the chosen clock", textOf("t1") && textOf("t1").value === "14:30", textOf("t1") && textOf("t1").value);
    check("an opted-out box is left alone", !textOf("dnat"));
    check("the visible box carries di-type-* for the page own date rules", t && t.classList.contains("di-type-date") && textOf("t1").classList.contains("di-type-time"));

    d1.value = "2026-12-25";
    check("code setting the value is followed", t && t.value === "25 Dec 2026", t && t.value);

    let fired = [];
    d1.addEventListener("input", () => fired.push("input"));
    d1.addEventListener("change", () => fired.push("change"));
    t.focus(); t.value = "5 aug 2026"; t.dispatchEvent(new Event("input")); t.blur();
    await tick();
    check("a typed date is read in the chosen format", d1.value === "2026-08-05", d1.value);
    check("input and change fire on the real box", fired.join(",") === "input,change", fired.join(","));

    t.focus(); t.value = "32 Foo 2026"; t.blur(); await tick();
    check("a date that cannot be read is refused", d1.value === "2026-08-05" && !t.checkValidity(), t.validationMessage);
    check("and says how to write one", /05 Aug 2026/.test(t.validationMessage), t.validationMessage);

    const dt = textOf("dt1"), dtn = document.getElementById("dt1");
    dt.focus(); dt.value = "1 Mar 2027 9:05 pm"; dt.blur(); await tick();
    check("a typed date and time is read, 12-hour input accepted", dtn.value === "2027-03-01T21:05", dtn.value);

    const tt = textOf("t1"), ttn = document.getElementById("t1");
    tt.focus(); tt.value = "0745"; tt.blur(); await tick();
    check("a time typed without a colon is read", ttn.value === "07:45", ttn.value);

    check("required is mirrored onto the visible box", textOf("dreq") && textOf("dreq").required);
    document.getElementById("d1").disabled = true; await tick();
    check("disabled is mirrored", t.disabled);
    document.getElementById("d1").disabled = false; await tick();

    document.getElementById("later").innerHTML = "<input type=\"date\" id=\"d2\" value=\"2026-02-03\">";
    await tick();
    check("a box added later is upgraded", textOf("d2") && textOf("d2").value === "03 Feb 2026", textOf("d2") && textOf("d2").value);

    // The calendar: weeks start on Sunday, and a click picks the day.
    t.focus(); t.click(); await tick();
    const pop = document.querySelector(".di-pop");
    check("focusing opens the calendar", !!pop);
    const dows = pop ? [...pop.querySelectorAll(".di-dow")].map(x => x.textContent) : [];
    check("the week starts on the chosen day", dows[0] === "Sun" && dows[1] === "Mon", dows.join(" "));
    const day = pop && pop.querySelector("[data-day=\"2026-8-20\"]");
    if (day) day.click();
    await tick();
    check("clicking a day sets it", d1.value === "2026-08-20" && t.value === "20 Aug 2026", d1.value + " / " + t.value);
    check("and closes the calendar for a date box", !document.querySelector(".di-pop"));

    t.click(); await tick();
    const clr = document.querySelector(".di-pop [data-act=\"clear\"]");
    if (clr) clr.click();
    await tick();
    check("Clear empties it", d1.value === "" && t.value === "", d1.value);

    document.getElementById("f").reset(); await tick();
    check("a form reset is followed", t.value === "05 Aug 2026", t.value);
  } catch (e) {
    check("no script errors", false, String(e && e.stack || e));
  }
  document.getElementById("result").textContent = JSON.stringify(checks);
})();
</script></body></html>';
exit(ticketWorkspaceBrowser(['index.html' => $html]));
