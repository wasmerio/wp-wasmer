(function () {
  "use strict";

  if (!window.wasmerAdminMenu || !window.wasmerAdminMenu.dashboardUrl) {
    return;
  }

  var links = document.querySelectorAll("#adminmenu a");
  for (var index = 0; index < links.length; index += 1) {
    if (links[index].href !== window.wasmerAdminMenu.dashboardUrl) {
      continue;
    }
    links[index].target = "_blank";
    links[index].rel = "noopener noreferrer";
    break;
  }
})();
