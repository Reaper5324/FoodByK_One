<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Menu | Food by K</title>
  <link rel="stylesheet" href="../../public assets/css/style.css">
  <style>
    .menu-page { max-width: 1180px; margin: 0 auto; padding: 32px 7%; }
    .menu-header { display: flex; justify-content: space-between; gap: 20px; align-items: end; margin-bottom: 24px; }
    .menu-header h1 { margin-bottom: 4px; }
    .menu-header p { color: var(--food-grey); }
    .menu-toolbar { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; }
    .menu-search { display: flex; flex: 1 1 280px; gap: 8px; }
    .menu-search input { flex: 1; min-width: 0; padding: 12px; border: 1px solid var(--food-border); border-radius: 6px; }
    .category-list { display: flex; flex-wrap: wrap; gap: 8px; }
    .category-list button { padding: 10px 16px; border: 1px solid var(--food-border); border-radius: 6px; background: var(--food-white); cursor: pointer; }
    .category-list button.active { background: var(--food-black); color: var(--food-white); }
    .menu-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 20px; }
    .menu-card { display: flex; flex-direction: column; background: var(--food-white); border: 1px solid var(--food-border); border-radius: 8px; overflow: hidden; }
    .menu-card-image { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; background: var(--food-light); }
    .menu-card-body { display: flex; flex: 1; flex-direction: column; padding: 18px; }
    .menu-card-body h2 { font-size: 20px; margin-bottom: 6px; }
    .menu-card-body p { color: var(--food-grey); margin-bottom: 14px; }
    .menu-card-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: auto; }
    .menu-price { font-weight: 800; white-space: nowrap; }
    .menu-message { padding: 18px; background: var(--food-white); border: 1px solid var(--food-border); border-radius: 6px; }
    @media (max-width: 640px) { .menu-header { display: block; } .menu-header .btn { margin-top: 14px; } }
  </style>
</head>
<body>
<header class="navbar">
  <div class="logo"><a href="../../src/index.html">FOOD<span>BY K</span></a></div>
  <nav>
    <a href="menu.php" class="active">Menu</a>
    <a href="cart.php">Cart</a>
    <a href="../../pages/auth/login.html">Account</a>
  </nav>
</header>

<main class="menu-page">
  <div class="menu-header">
    <div>
      <h1>Our Menu</h1>
      <p>Fresh food made for your next order.</p>
    </div>
    <a class="btn btn-secondary" href="cart.php">View cart</a>
  </div>

  <div class="menu-toolbar">
    <form id="menuSearchForm" class="menu-search">
      <input id="menuSearch" type="search" placeholder="Search burgers, fries, wings..." aria-label="Search menu">
      <button class="btn btn-primary" type="submit">Search</button>
    </form>
    <div id="categoryList" class="category-list" aria-label="Menu categories"></div>
  </div>

  <div id="menuMessage" class="menu-message" hidden></div>
  <section id="menuGrid" class="menu-grid" aria-live="polite"></section>
</main>

<script src="../../public%20assets/js/config.js"></script>
<script src="../../public%20assets/js/api/api.js"></script>
<script src="../../public%20assets/js/menu.js"></script>
</body>
</html>
