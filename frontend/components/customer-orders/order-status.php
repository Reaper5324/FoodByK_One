<?php
// render status badge
function renderOrderStatus($status) {
    $class = "secondary";
    if ($status === "pending") $class = "warning";
    if ($status === "confirmed") $class = "info";
    if ($status === "completed") $class = "success";
    echo "<span class='badge badge-$class'>" . htmlspecialchars($status) . "</span>";
}
