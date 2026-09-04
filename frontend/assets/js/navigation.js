/* =========================================
FOOD BY K - P1
SHARED WEBSITE STYLES
========================================= */

/* ---------- Brand Colours ---------- */

:root {
--food-red: #D71920;
--food-red-dark: #A90F15;
--food-yellow: #FFC928;
--food-black: #111111;
--food-dark: #1C1C1C;
--food-white: #FFFFFF;
--food-light: #F7F7F7;
--food-grey: #6B6B6B;
--food-border: #E2E2E2;
}

/* ---------- Reset ---------- */

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  }

html {
scroll-behavior: smooth;
}

body {
font-family: Arial, Helvetica, sans-serif;
background-color: var(--food-light);
color: var(--food-black);
line-height: 1.6;
}

/* ---------- Links ---------- */

a {
color: inherit;
text-decoration: none;
}

/* ---------- Buttons ---------- */

.btn {
display: inline-block;
padding: 13px 24px;
border: none;
border-radius: 6px;
font-size: 15px;
font-weight: 700;
cursor: pointer;
transition: all 0.2s ease;
text-align: center;
}

.btn-primary {
background-color: var(--food-red);
color: var(--food-white);
}

.btn-primary:hover {
background-color: var(--food-red-dark);
transform: translateY(-1px);
}

.btn-secondary {
background-color: var(--food-yellow);
color: var(--food-black);
}

.btn-secondary:hover {
background-color: #E5AE00;
transform: translateY(-1px);
}

/* ---------- Shared Navigation ---------- */

.navbar {
width: 100%;
min-height: 72px;
padding: 0 7%;
background-color: var(--food-black);

```
display: flex;
align-items: center;
justify-content: space-between;
```

}

.logo a {
color: var(--food-white);
font-size: 24px;
font-weight: 800;
letter-spacing: 1px;
}

.logo span {
color: var(--food-yellow);
}

.navbar nav {
display: flex;
align-items: center;
gap: 25px;
}

.navbar nav a {
color: var(--food-white);
font-size: 14px;
font-weight: 600;
transition: color 0.2s ease;
}

.navbar nav a:hover {
color: var(--food-yellow);
}

.navbar nav a.active {
color: var(--food-yellow);
}

/* ---------- Hidden Elements ---------- */

.hidden {
display: none !important;
}

/* ---------- Logout Button ---------- */

.logout-nav {
background: none;
border: none;
padding: 0;
color: var(--food-white);
font-family: inherit;
font-size: 14px;
font-weight: 600;
cursor: pointer;
}

.logout-nav:hover {
color: var(--food-yellow);
}

/* ---------- Footer ---------- */

footer {
padding: 25px;
background-color: var(--food-black);
color: var(--food-white);
text-align: center;
font-size: 14px;
}

/* ---------- Responsive Navigation ---------- */

@media (max-width: 768px) {

```
.navbar {
    padding: 18px 5%;
    flex-direction: column;
    gap: 15px;
}

.navbar nav {
    flex-wrap: wrap;
    justify-content: center;
    gap: 15px;
}
```

}



