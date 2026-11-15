<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Searchable company lists (GH #358) - assets/js/select-search.js, in headless
 * Chrome. No database.
 *
 * A page publishes window.SELECT_SEARCH the way Tz::scriptTag() does (after 15),
 * then checks that a 3,000-company list becomes a search box showing 10, that
 * typing narrows it, that a pick sets the real select and fires change, that code
 * setting the value or refilling the options is followed, and that the lists it
 * must NOT touch - short ones, non-company ones, multiple ones, opted-out ones -
 * are left alone.
 *
 *   php tests/select-search.php
 * Control: SELECT_SEARCH_CONTROL=1 runs without the script; the upgrade checks fail.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
$js = (string)file_get_contents(dirname(__DIR__) . '/assets/js/select-search.js');
if (getenv('SELECT_SEARCH_CONTROL')) $js = '/* control: no script */';
$html = '<!doctype html><html><head><meta charset="utf-8"></head><body>
<select id="companySelect"></select>
<select id="newTicketCompany"><option value="">-</option><option value="1">Acme</option><option value="2">Beta</option></select>
<select id="statusSelect"></select>
<select id="teamCompanies" multiple></select>
<span data-native-select><select id="pinnedCompany"></select></span>
<select id="itemScope" data-searchable></select>
<div id="later"></div>
<div id="menu"></div><div id="ticks"></div><div id="shortMenu"></div>
<pre id="result">RUNNING</pre>
<script>
window.SELECT_SEARCH = { after: 15, text: { search: "Type to search", none: "No matches.", more: "Showing {n} of {total} - type to narrow." } };
function fill(id, n, prefix) { var s = document.getElementById(id), h = ""; for (var i = 1; i <= n; i++) h += "<option value=\"" + i + "\">" + prefix + " " + i + "</option>"; s.innerHTML = h; }
fill("companySelect", 3000, "Company"); fill("statusSelect", 40, "Status"); fill("teamCompanies", 40, "Company");
fill("pinnedCompany", 40, "Company"); fill("itemScope", 40, "Scope");
document.getElementById("companySelect").insertAdjacentHTML("afterbegin", "<option value=\"\">All companies</option><option value=\"z\">Zürich Ölwerke</option>");
document.getElementById("companySelect").value = "";
</script>
<script>' . str_replace('</script', '<\/script', $js) . '</script>
<script>
(async function () {
  const checks = [];
  const check = (label, pass, detail) => checks.push({ label, pass: !!pass, detail: detail === undefined ? null : String(detail) });
  const tick = () => new Promise(r => setTimeout(r, 40));
  const boxOf = id => { const s = document.getElementById(id); const w = s.parentElement; return w && w.classList.contains("ss-wrap") ? w.querySelector(".ss-box") : null; };
  try {
    await tick();
    const sel = document.getElementById("companySelect"), box = boxOf("companySelect");
    check("a 3,000-company list becomes a search box", !!box);
    check("the real select is hidden, not removed", sel && sel.classList.contains("ss-native") && getComputedStyle(sel).display === "none");
    check("the box shows the chosen company", box && box.textContent.indexOf("All companies") === 0, box && box.textContent);
    check("a 3-company list is left alone", !boxOf("newTicketCompany"));
    check("a long list that is not companies is left alone", !boxOf("statusSelect"));
    check("a multiple select is left alone", !boxOf("teamCompanies"));
    check("data-native-select opts out", !boxOf("pinnedCompany"));
    check("data-searchable opts in", !!boxOf("itemScope"));

    let fired = [];
    sel.addEventListener("input", () => fired.push("input"));
    sel.addEventListener("change", () => fired.push("change"));
    box.click(); await tick();
    const pop = document.querySelector(".ss-pop");
    const items = () => pop ? [...pop.querySelectorAll("li")].map(li => li.textContent) : [];
    check("clicking opens it, with 10 shown", pop && items().length === 10, items().length);
    check("and says how many there are", pop && /of 3,?002/.test(pop.querySelector(".ss-more").textContent), pop && pop.querySelector(".ss-more").textContent);
    const find = pop.querySelector("input");
    find.value = "company 2999"; find.dispatchEvent(new Event("input")); await tick();
    check("typing narrows it", items().length === 1 && items()[0] === "Company 2999", items().join("|"));
    find.value = "zurich olwerke"; find.dispatchEvent(new Event("input")); await tick();
    check("accents do not matter", items()[0] === "Zürich Ölwerke", items().join("|"));
    find.value = "nothing like this"; find.dispatchEvent(new Event("input")); await tick();
    check("no match says so", items().length === 0 && /No matches/.test(pop.querySelector(".ss-more").textContent));
    find.value = "company 1234"; find.dispatchEvent(new Event("input")); await tick();
    find.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter" })); await tick();
    check("Enter picks it into the real select", sel.value === "1234", sel.value);
    check("input and change fire on the real select", fired.join(",") === "input,change", fired.join(","));
    check("the box follows", box.textContent.indexOf("Company 1234") === 0, box.textContent);
    check("and the list closes", !document.querySelector(".ss-pop"));

    sel.value = "77"; await tick();
    check("code setting value is followed", box.textContent.indexOf("Company 77") === 0, box.textContent);
    sel.selectedIndex = 0; await tick();
    check("code setting selectedIndex is followed", box.textContent.indexOf("All companies") === 0, box.textContent);
    sel.innerHTML = Array.from({length: 20}, (_, i) => "<option value=\"n" + i + "\">New " + i + "</option>").join(""); await tick();
    check("refilled options are followed", box.textContent.indexOf("New 0") === 0, box.textContent);
    sel.disabled = true; await tick();
    check("disabled is mirrored", box.disabled);
    sel.disabled = false; sel.style.display = "none"; await tick();
    check("hiding the select hides the box", sel.parentElement.hidden);
    sel.style.display = ""; await tick();
    check("and showing it shows the box again, the select staying hidden", !sel.parentElement.hidden && getComputedStyle(sel).display === "none");

    const small = document.getElementById("newTicketCompany");
    small.insertAdjacentHTML("beforeend", Array.from({length: 30}, (_, i) => "<option>More " + i + "</option>").join("")); await tick();
    check("a short company list that grows is upgraded", !!boxOf("newTicketCompany"));
    document.getElementById("later").innerHTML = "<select name=\"company_id\">" + Array.from({length: 30}, (_, i) => "<option>L" + i + "</option>").join("") + "</select>";
    await tick();
    check("a company list added later is upgraded (by name)", !!document.querySelector("#later .ss-box"));

    // filterList(): a right-click submenu and a tick list (not <select>s).
    const menu = document.getElementById("menu");
    let picked = null;
    menu.innerHTML = Array.from({length: 300}, (_, i) => "<div class=\"item\" data-id=\"" + i + "\">Company " + i + "</div>").join("");
    menu.addEventListener("click", e => { const it = e.target.closest(".item"); if (it) picked = it.getAttribute("data-id"); });
    FreeitsmSelectSearch.filterList(menu, ".item", { cap: 10 });
    const vis = c => [...c.querySelectorAll(".item, label")].filter(x => x.style.display !== "none").length;
    check("a long menu gets a search box, showing 10", menu.querySelector(".ss-filter input") && vis(menu) === 10, vis(menu));
    const mi = menu.querySelector(".ss-filter input");
    mi.value = "company 29"; mi.dispatchEvent(new Event("input"));
    check("typing narrows the menu", vis(menu) === 10 && /of 11/.test(menu.querySelector(".ss-more").textContent), vis(menu) + " " + menu.querySelector(".ss-more").textContent);
    mi.click();
    check("clicking the box picks nothing", picked === null);
    mi.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter" }));
    check("Enter picks the first match", picked === "29", picked);
    const ticks = document.getElementById("ticks");
    ticks.innerHTML = Array.from({length: 40}, (_, i) => "<label><input type=\"checkbox\"> Company " + i + "</label>").join("");
    FreeitsmSelectSearch.filterList(ticks, "label");
    check("a tick list shows every row - no cap", vis(ticks) === 40, vis(ticks));
    const ti = ticks.querySelector(".ss-filter input");
    ti.value = "company 3"; ti.dispatchEvent(new Event("input"));
    check("typing filters the tick list", vis(ticks) === 11, vis(ticks));
    ti.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter" }));
    check("Enter ticks nothing in a tick list", !ticks.querySelector("input[type=checkbox]:checked"));
    FreeitsmSelectSearch.filterList(ticks, "label");
    check("calling it again does not add a second box", ticks.querySelectorAll(".ss-filter").length === 1);
    const sm = document.getElementById("shortMenu");
    sm.innerHTML = "<div class=\"item\">A</div><div class=\"item\">B</div>";
    FreeitsmSelectSearch.filterList(sm, ".item", { cap: 10 });
    check("a short menu is left alone", !sm.querySelector(".ss-filter"));
  } catch (e) {
    check("no script errors", false, String(e && e.stack || e));
  }
  document.getElementById("result").textContent = JSON.stringify(checks);
})();
</script></body></html>';
exit(ticketWorkspaceBrowser(['index.html' => $html]));
