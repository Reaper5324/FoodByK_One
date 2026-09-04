/* ========================================
   MOBILE SIDEBAR
======================================== */

const sidebarToggle = document.querySelector(".sidebar-toggle");
const sidebar = document.querySelector(".sidebar");

if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener("click", () => {
        sidebar.classList.toggle("active");
    });
}


/* ========================================
   ORDER SEARCH
======================================== */

const orderSearch = document.querySelector("#orderSearch");
const orderRows = document.querySelectorAll("#ordersTable tbody tr");

if (orderSearch && orderRows.length > 0) {

    orderSearch.addEventListener("input", () => {

        const searchValue = orderSearch.value.toLowerCase();

        orderRows.forEach(row => {

            const rowText = row.textContent.toLowerCase();

            row.style.display =
                rowText.includes(searchValue) ? "" : "none";

        });

    });

}


/* ========================================
   ORDER FILTERS
======================================== */

const filterButtons = document.querySelectorAll(".filter-btn");

if (filterButtons.length > 0) {

    filterButtons.forEach(button => {

        button.addEventListener("click", () => {

            /* Remove active state from all buttons */
            filterButtons.forEach(btn => {
                btn.classList.remove("active");
            });

            /* Add active state to clicked button */
            button.classList.add("active");

            const filter = button.dataset.filter;

            orderRows.forEach(row => {

                if (
                    filter === "all" ||
                    row.dataset.status === filter
                ) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }

            });

        });

    });

}


/* ========================================
   ORDER MODAL
======================================== */

const orderModal = document.querySelector("#orderModal");
const manageButtons = document.querySelectorAll(".manage-order-btn");
const closeModal = document.querySelector(".close-modal");

const acceptButton = document.querySelector(".accept-order-btn");
const adjustButton = document.querySelector(".adjust-time-btn");
const declineButton = document.querySelector(".decline-order-btn");

const confirmationSection =
    document.querySelector("#confirmationSection");

const adjustmentSection =
    document.querySelector("#adjustmentSection");

const declineSection =
    document.querySelector("#declineSection");

const statusSection =
    document.querySelector("#statusSection");

const adjustedTime =
    document.querySelector("#adjustedTime");

const confirmTimeButton =
    document.querySelector(".confirm-time-btn");

const confirmDeclineButton =
    document.querySelector(".confirm-decline-btn");

const saveStatusButton =
    document.querySelector(".save-status-btn");

const statusSelect =
    document.querySelector("#statusSelect");

const declineReason =
    document.querySelector("#declineReason");


let selectedRow = null;


/* ========================================
   OPEN ORDER MODAL
======================================== */

if (manageButtons.length > 0) {

    manageButtons.forEach(button => {

        button.addEventListener("click", () => {

            selectedRow = button.closest("tr");

            if (!selectedRow || !orderModal) return;


            const cells =
                selectedRow.querySelectorAll("td");

            const currentStatus =
                selectedRow.dataset.status;


            /* Fill modal with order information */

            const modalOrderId =
                document.querySelector("#modalOrderId");

            const modalCustomer =
                document.querySelector("#modalCustomer");

            const modalType =
                document.querySelector("#modalType");

            const modalTime =
                document.querySelector("#modalTime");

            const modalTotal =
                document.querySelector("#modalTotal");


            if (modalOrderId) {
                modalOrderId.textContent =
                    cells[0].textContent.trim();
            }

            if (modalCustomer) {
                modalCustomer.textContent =
                    cells[1].innerText.trim();
            }

            if (modalType) {
                modalType.textContent =
                    cells[2].innerText.trim();
            }

            if (modalTime) {
                modalTime.textContent =
                    cells[3].textContent.trim();
            }

            if (modalTotal) {
                modalTotal.textContent =
                    cells[4].textContent.trim();
            }


            /* Reset modal sections */

            if (confirmationSection) {
                confirmationSection.style.display = "none";
            }

            if (adjustmentSection) {
                adjustmentSection.classList.remove("show");
            }

            if (declineSection) {
                declineSection.classList.remove("show");
            }

            if (statusSection) {
                statusSection.classList.remove("show");
            }


            /* Reset inputs */

            if (adjustedTime) {
                adjustedTime.value = "";
            }

            if (declineReason) {
                declineReason.value = "";
            }


            /* Show the correct controls depending on status */

            if (currentStatus === "awaiting") {

                if (confirmationSection) {
                    confirmationSection.style.display = "block";
                }

            } else if (currentStatus !== "declined") {

                if (statusSection) {
                    statusSection.classList.add("show");
                }

                if (statusSelect) {
                    statusSelect.value = currentStatus;
                }

            }


            /* Open modal */

            orderModal.classList.add("show");

        });

    });

}


/* ========================================
   ACCEPT ORDER
======================================== */

if (acceptButton) {

    acceptButton.addEventListener("click", () => {

        if (!selectedRow) return;


        selectedRow.dataset.status = "accepted";


        const statusElement =
            selectedRow.querySelector(".status");


        if (statusElement) {

            statusElement.textContent = "Accepted";

            statusElement.className =
                "status accepted";

        }


        /* Hide staff decision buttons */

        if (confirmationSection) {
            confirmationSection.style.display = "none";
        }


        /* Show status update section */

        if (statusSection) {
            statusSection.classList.add("show");
        }

        if (statusSelect) {
            statusSelect.value = "accepted";
        }

    });

}


/* ========================================
   ADJUST TIME
======================================== */

if (adjustButton) {

    adjustButton.addEventListener("click", () => {

        if (adjustmentSection) {
            adjustmentSection.classList.add("show");
        }

        if (declineSection) {
            declineSection.classList.remove("show");
        }

    });

}


/* ========================================
   CONFIRM NEW TIME
======================================== */

if (confirmTimeButton) {

    confirmTimeButton.addEventListener("click", () => {

        if (!selectedRow || !adjustedTime) return;


        if (!adjustedTime.value) {

            alert("Please select a new collection or delivery time.");

            return;

        }


        const cells =
            selectedRow.querySelectorAll("td");


        /* Update table time */

        cells[3].textContent =
            adjustedTime.value;


        /* Update modal time */

        const modalTime =
            document.querySelector("#modalTime");

        if (modalTime) {

            modalTime.textContent =
                adjustedTime.value;

        }


        /* Accept the order */

        selectedRow.dataset.status =
            "accepted";


        const statusElement =
            selectedRow.querySelector(".status");


        if (statusElement) {

            statusElement.textContent =
                "Accepted";

            statusElement.className =
                "status accepted";

        }


        /* Update modal sections */

        if (confirmationSection) {
            confirmationSection.style.display = "none";
        }

        if (adjustmentSection) {
            adjustmentSection.classList.remove("show");
        }

        if (statusSection) {
            statusSection.classList.add("show");
        }

        if (statusSelect) {
            statusSelect.value = "accepted";
        }


        alert("New fulfilment time confirmed and order accepted.");

    });

}


/* ========================================
   DECLINE ORDER
======================================== */

if (declineButton) {

    declineButton.addEventListener("click", () => {

        if (declineSection) {
            declineSection.classList.add("show");
        }

        if (adjustmentSection) {
            adjustmentSection.classList.remove("show");
        }

    });

}


/* ========================================
   CONFIRM DECLINE
======================================== */

if (confirmDeclineButton) {

    confirmDeclineButton.addEventListener("click", () => {

        if (!selectedRow || !declineReason) return;


        const reason =
            declineReason.value.trim();


        if (!reason) {

            alert(
                "Please enter a reason for declining the order."
            );

            return;

        }


        /* Update order status */

        selectedRow.dataset.status =
            "declined";


        const statusElement =
            selectedRow.querySelector(".status");


        if (statusElement) {

            statusElement.textContent =
                "Declined";

            statusElement.className =
                "status declined";

        }


        alert(
            "Order declined.\n\nReason: " + reason
        );


        closeOrderModal();

    });

}


/* ========================================
   UPDATE ORDER STATUS
======================================== */

if (saveStatusButton) {

    saveStatusButton.addEventListener("click", () => {

        if (!selectedRow || !statusSelect) return;


        const newStatus =
            statusSelect.value;


        /* Update row data status */

        selectedRow.dataset.status =
            newStatus;


        /* Update visible status */

        const statusElement =
            selectedRow.querySelector(".status");


        if (statusElement) {

            const formattedStatus =
                newStatus.charAt(0).toUpperCase() +
                newStatus.slice(1);


            statusElement.textContent =
                formattedStatus;


            statusElement.className =
                "status " + newStatus;

        }


        alert(
            "Order status updated to " +
            newStatus.charAt(0).toUpperCase() +
            newStatus.slice(1)
        );


        closeOrderModal();

    });

}


/* ========================================
   CLOSE MODAL FUNCTION
======================================== */

function closeOrderModal() {

    if (orderModal) {

        orderModal.classList.remove("show");

    }

}


/* Close button */

if (closeModal) {

    closeModal.addEventListener(
        "click",
        closeOrderModal
    );

}


/* Close when clicking outside the modal */

if (orderModal) {

    orderModal.addEventListener("click", (event) => {

        if (event.target === orderModal) {

            closeOrderModal();

        }

    });

}

/* ========================================
   MENU ITEMS MANAGEMENT
======================================== */

const menuModal = document.querySelector("#menuModal");
const addMenuBtn = document.querySelector("#addMenuBtn");
const closeMenuModal = document.querySelector(".close-menu-modal");

const menuForm = document.querySelector("#menuForm");

const menuSearch = document.querySelector("#menuSearch");
const menuCards = document.querySelectorAll(".menu-card");

const menuFilterButtons =
    document.querySelectorAll(".menu-filter-btn");

const menuModalTitle =
    document.querySelector("#menuModalTitle");

const itemName =
    document.querySelector("#itemName");

const itemCategory =
    document.querySelector("#itemCategory");

const itemPrice =
    document.querySelector("#itemPrice");

const itemImage =
    document.querySelector("#itemImage");


let editingMenuCard = null;


/* ========================================
   OPEN ADD ITEM MODAL
======================================== */

if (addMenuBtn && menuModal) {

    addMenuBtn.addEventListener("click", () => {

        editingMenuCard = null;

        menuModalTitle.textContent =
            "Add New Menu Item";

        menuForm.reset();

        menuModal.classList.add("show");

    });

}


/* ========================================
   CLOSE MENU MODAL
======================================== */

function closeMenuItemModal() {

    if (menuModal) {
        menuModal.classList.remove("show");
    }

}


if (closeMenuModal) {

    closeMenuModal.addEventListener(
        "click",
        closeMenuItemModal
    );

}


if (menuModal) {

    menuModal.addEventListener("click", (event) => {

        if (event.target === menuModal) {

            closeMenuItemModal();

        }

    });

}


/* ========================================
   EDIT MENU ITEM
======================================== */

document.querySelectorAll(".edit-menu-btn").forEach(button => {

    button.addEventListener("click", () => {

        const card =
            button.closest(".menu-card");

        if (!card) return;

        editingMenuCard = card;


        const name =
            card.querySelector("h3").textContent;

        const category =
            card.dataset.category;

        const price =
            card.querySelector(".menu-price").textContent
                .replace("R", "")
                .trim();


        const image =
            card.querySelector("img").src;


        itemName.value = name;

        itemCategory.value = category;

        itemPrice.value = price;

        itemImage.value = image;


        menuModalTitle.textContent =
            "Edit Menu Item";


        menuModal.classList.add("show");

    });

});


/* ========================================
   SAVE MENU ITEM
======================================== */

if (menuForm) {

    menuForm.addEventListener("submit", (event) => {

        event.preventDefault();


        const name =
            itemName.value.trim();

        const category =
            itemCategory.value;

        const price =
            itemPrice.value;

        const image =
            itemImage.value.trim();


        if (!name || !category || !price) {

            alert(
                "Please complete all required fields."
            );

            return;

        }


        /* ====================================
           EDIT EXISTING ITEM
        ==================================== */

        if (editingMenuCard) {

            const title =
                editingMenuCard.querySelector("h3");

            const categoryText =
                editingMenuCard.querySelector(".menu-category");

            const priceText =
                editingMenuCard.querySelector(".menu-price");

            const imageElement =
                editingMenuCard.querySelector("img");


            title.textContent = name;

            categoryText.textContent =
                category.toUpperCase();

            priceText.textContent =
                "R" + parseFloat(price).toFixed(2);


            if (image) {

                imageElement.src = image;

            }


            editingMenuCard.dataset.category =
                category;


            alert("Menu item updated successfully.");

        }


        /* ====================================
           ADD NEW ITEM
        ==================================== */

        else {

            const menuGrid =
                document.querySelector("#menuGrid");


            const newCard =
                document.createElement("div");


            newCard.className =
                "menu-card";


            newCard.dataset.category =
                category;


            const imageSource =
                image ||
                "https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=800&q=80";


            newCard.innerHTML = `

                <div class="menu-image">

                    <img
                        src="${imageSource}"
                        alt="${name}"
                    >

                    <span class="availability available">
                        Available
                    </span>

                </div>


                <div class="menu-card-content">

                    <p class="menu-category">
                        ${category.toUpperCase()}
                    </p>

                    <h3>
                        ${name}
                    </h3>

                    <p class="menu-price">
                        R${parseFloat(price).toFixed(2)}
                    </p>


                    <div class="menu-card-actions">

                        <button class="edit-menu-btn">

                            <i class="fa-solid fa-pen"></i>

                            Edit

                        </button>


                        <button class="availability-btn">

                            <i class="fa-solid fa-toggle-on"></i>

                            Available

                        </button>


                        <button class="delete-menu-btn">

                            <i class="fa-solid fa-trash"></i>

                        </button>

                    </div>

                </div>

            `;


            menuGrid.appendChild(newCard);


            addMenuCardEvents(newCard);


            alert("Menu item added successfully.");

        }


        closeMenuItemModal();

    });

}


/* ========================================
   AVAILABILITY TOGGLE
======================================== */

function addAvailabilityEvent(card) {

    const button =
        card.querySelector(".availability-btn");

    const badge =
        card.querySelector(".availability");


    if (!button || !badge) return;


    button.addEventListener("click", () => {

        const isAvailable =
            badge.classList.contains("available");


        if (isAvailable) {

            badge.classList.remove("available");

            badge.classList.add("unavailable");

            badge.textContent =
                "Unavailable";


            button.classList.add(
                "unavailable-btn"
            );


            button.innerHTML = `
                <i class="fa-solid fa-toggle-off"></i>
                Unavailable
            `;

        }

        else {

            badge.classList.remove("unavailable");

            badge.classList.add("available");

            badge.textContent =
                "Available";


            button.classList.remove(
                "unavailable-btn"
            );


            button.innerHTML = `
                <i class="fa-solid fa-toggle-on"></i>
                Available
            `;

        }

    });

}


/* ========================================
   DELETE MENU ITEM
======================================== */

function addDeleteEvent(card) {

    const button =
        card.querySelector(".delete-menu-btn");


    if (!button) return;


    button.addEventListener("click", () => {

        const name =
            card.querySelector("h3").textContent;


        const confirmed =
            confirm(
                `Are you sure you want to delete "${name}"?`
            );


        if (confirmed) {

            card.remove();

        }

    });

}


/* ========================================
   EDIT EVENT FOR NEW CARDS
======================================== */

function addEditEvent(card) {

    const button =
        card.querySelector(".edit-menu-btn");


    if (!button) return;


    button.addEventListener("click", () => {

        editingMenuCard = card;


        itemName.value =
            card.querySelector("h3").textContent;


        itemCategory.value =
            card.dataset.category;


        itemPrice.value =
            card.querySelector(".menu-price")
                .textContent
                .replace("R", "")
                .trim();


        itemImage.value =
            card.querySelector("img").src;


        menuModalTitle.textContent =
            "Edit Menu Item";


        menuModal.classList.add("show");

    });

}


/* ========================================
   ADD EVENTS TO MENU CARD
======================================== */

function addMenuCardEvents(card) {

    addAvailabilityEvent(card);

    addDeleteEvent(card);

    addEditEvent(card);

}


/* Add events to existing cards */

menuCards.forEach(card => {

    addMenuCardEvents(card);

});


/* ========================================
   MENU SEARCH
======================================== */

if (menuSearch) {

    menuSearch.addEventListener("input", () => {

        const searchValue =
            menuSearch.value.toLowerCase().trim();


        menuCards.forEach(card => {

            const cardText =
                card.textContent.toLowerCase();


            if (cardText.includes(searchValue)) {

                card.style.display = "";

            }

            else {

                card.style.display = "none";

            }

        });

    });

}


/* ========================================
   CATEGORY FILTERS
======================================== */

if (menuFilterButtons.length > 0) {

    menuFilterButtons.forEach(button => {

        button.addEventListener("click", () => {


            menuFilterButtons.forEach(btn => {

                btn.classList.remove("active");

            });


            button.classList.add("active");


            const category =
                button.dataset.category;


            document.querySelectorAll(".menu-card")
                .forEach(card => {

                    if (
                        category === "all" ||
                        card.dataset.category === category
                    ) {

                        card.style.display = "";

                    }

                    else {

                        card.style.display = "none";

                    }

                });

        });

    });

}



/* ========================================
   CATEGORY MANAGEMENT
======================================== */

const categoryModal =
    document.querySelector("#categoryModal");

const addCategoryBtn =
    document.querySelector("#addCategoryBtn");

const closeCategoryModal =
    document.querySelector(".close-category-modal");

const categoryForm =
    document.querySelector("#categoryForm");

const categorySearch =
    document.querySelector("#categorySearch");

const categoryModalTitle =
    document.querySelector("#categoryModalTitle");

const categoryName =
    document.querySelector("#categoryName");

const categoryDescription =
    document.querySelector("#categoryDescription");


let editingCategoryRow = null;


/* ========================================
   OPEN ADD CATEGORY MODAL
======================================== */

if (addCategoryBtn) {

    addCategoryBtn.addEventListener("click", () => {

        editingCategoryRow = null;

        categoryModalTitle.textContent =
            "Add New Category";

        categoryForm.reset();

        categoryModal.classList.add("show");

    });

}


/* ========================================
   CLOSE CATEGORY MODAL
======================================== */

function closeCategoryModalFunction() {

    if (categoryModal) {

        categoryModal.classList.remove("show");

    }

}


if (closeCategoryModal) {

    closeCategoryModal.addEventListener(
        "click",
        closeCategoryModalFunction
    );

}


if (categoryModal) {

    categoryModal.addEventListener("click", (event) => {

        if (event.target === categoryModal) {

            closeCategoryModalFunction();

        }

    });

}


/* ========================================
   EDIT CATEGORY
======================================== */

function addCategoryEditEvent(row) {

    const editButton =
        row.querySelector(".category-edit-btn");

    if (!editButton) return;


    editButton.addEventListener("click", () => {

        editingCategoryRow = row;


        const name =
            row.querySelector("strong").textContent;


        const description =
            row.querySelector("td:nth-child(2)")
                .textContent
                .trim();


        categoryName.value = name;

        categoryDescription.value =
            description;


        categoryModalTitle.textContent =
            "Edit Category";


        categoryModal.classList.add("show");

    });

}


/* ========================================
   CATEGORY FORM SUBMIT
======================================== */

if (categoryForm) {

    categoryForm.addEventListener("submit", (event) => {

        event.preventDefault();


        const name =
            categoryName.value.trim();

        const description =
            categoryDescription.value.trim();


        if (!name) {

            alert(
                "Please enter a category name."
            );

            return;

        }


        /* ====================================
           EDIT EXISTING CATEGORY
        ==================================== */

        if (editingCategoryRow) {

            editingCategoryRow
                .querySelector("strong")
                .textContent = name;


            editingCategoryRow
                .querySelector("td:nth-child(2)")
                .textContent =
                    description ||
                    "No description provided";


            editingCategoryRow.dataset.category =
                name.toLowerCase()
                    .replace(/\s+/g, "-");


            alert(
                "Category updated successfully."
            );

        }


        /* ====================================
           ADD NEW CATEGORY
        ==================================== */

        else {

            const tableBody =
                document.querySelector(
                    "#categoryTable tbody"
                );


            const newRow =
                document.createElement("tr");


            const categorySlug =
                name.toLowerCase()
                    .replace(/\s+/g, "-");


            newRow.dataset.category =
                categorySlug;


            newRow.innerHTML = `

                <td>

                    <div class="category-name">

                        <div class="category-icon">

                            <i class="fa-solid fa-layer-group"></i>

                        </div>

                        <strong>
                            ${name}
                        </strong>

                    </div>

                </td>


                <td>
                    ${description || "No description provided"}
                </td>


                <td>
                    <strong>0</strong>
                </td>


                <td>

                    <span class="category-status active">
                        Active
                    </span>

                </td>


                <td>

                    <div class="category-actions">

                        <button
                            class="category-edit-btn"
                            title="Edit category"
                        >

                            <i class="fa-solid fa-pen"></i>

                        </button>


                        <button
                            class="category-toggle-btn"
                            title="Deactivate category"
                        >

                            <i class="fa-solid fa-toggle-on"></i>

                        </button>


                        <button
                            class="category-delete-btn"
                            title="Delete category"
                        >

                            <i class="fa-solid fa-trash"></i>

                        </button>

                    </div>

                </td>

            `;


            tableBody.appendChild(newRow);


            addCategoryEditEvent(newRow);

            addCategoryToggleEvent(newRow);

            addCategoryDeleteEvent(newRow);


            updateCategoryCounters();


            alert(
                "Category added successfully."
            );

        }


        closeCategoryModalFunction();

    });

}


/* ========================================
   CATEGORY TOGGLE
======================================== */

function addCategoryToggleEvent(row) {

    const toggleButton =
        row.querySelector(".category-toggle-btn");

    const status =
        row.querySelector(".category-status");


    if (!toggleButton || !status) return;


    toggleButton.addEventListener("click", () => {

        const isActive =
            status.classList.contains("active");


        if (isActive) {

            status.classList.remove("active");

            status.classList.add("inactive");

            status.textContent =
                "Inactive";


            toggleButton.innerHTML =
                `<i class="fa-solid fa-toggle-off"></i>`;

            toggleButton.title =
                "Activate category";

        }

        else {

            status.classList.remove("inactive");

            status.classList.add("active");

            status.textContent =
                "Active";


            toggleButton.innerHTML =
                `<i class="fa-solid fa-toggle-on"></i>`;

            toggleButton.title =
                "Deactivate category";

        }


        updateCategoryCounters();

    });

}


/* ========================================
   DELETE CATEGORY
======================================== */

function addCategoryDeleteEvent(row) {

    const deleteButton =
        row.querySelector(".category-delete-btn");


    if (!deleteButton) return;


    deleteButton.addEventListener("click", () => {

        const name =
            row.querySelector("strong").textContent;


        const itemCount =
            parseInt(
                row.querySelector(
                    "td:nth-child(3)"
                ).textContent
            );


        if (itemCount > 0) {

            alert(
                `"${name}" contains ${itemCount} menu item(s).\n\n` +
                `Remove or move those menu items before deleting this category.`
            );

            return;

        }


        const confirmed =
            confirm(
                `Are you sure you want to delete "${name}"?`
            );


        if (confirmed) {

            row.remove();

            updateCategoryCounters();

        }

    });

}


/* ========================================
   CATEGORY COUNTERS
======================================== */

function updateCategoryCounters() {

    const rows =
        document.querySelectorAll(
            "#categoryTable tbody tr"
        );


    let activeCount = 0;


    rows.forEach(row => {

        const status =
            row.querySelector(".category-status");


        if (
            status &&
            status.classList.contains("active")
        ) {

            activeCount++;

        }

    });


    const totalCategories =
        document.querySelector(
            "#totalCategories"
        );


    const activeCategories =
        document.querySelector(
            "#activeCategories"
        );


    if (totalCategories) {

        totalCategories.textContent =
            rows.length;

    }


    if (activeCategories) {

        activeCategories.textContent =
            activeCount;

    }

}


/* ========================================
   CATEGORY SEARCH
======================================== */

if (categorySearch) {

    categorySearch.addEventListener("input", () => {

        const searchValue =
            categorySearch.value
                .toLowerCase()
                .trim();


        const rows =
            document.querySelectorAll(
                "#categoryTable tbody tr"
            );


        rows.forEach(row => {

            const rowText =
                row.textContent.toLowerCase();


            if (rowText.includes(searchValue)) {

                row.style.display = "";

            }

            else {

                row.style.display = "none";

            }

        });

    });

}


/* ========================================
   INITIAL CATEGORY EVENTS
======================================== */

document
    .querySelectorAll(
        "#categoryTable tbody tr"
    )
    .forEach(row => {

        addCategoryEditEvent(row);

        addCategoryToggleEvent(row);

        addCategoryDeleteEvent(row);

    });



    /* ========================================
   PROMOTIONS PAGE
======================================== */

const promotionModal = document.getElementById("promotionModal");

if (promotionModal) {

    const promotionForm = document.getElementById("promotionForm");
    const promotionTableBody = document.getElementById("promotionTableBody");

    const addPromotionBtn = document.getElementById("addPromotionBtn");
    const closePromotionModal = document.getElementById("closePromotionModal");

    const promotionModalTitle = document.getElementById("promotionModalTitle");

    const promotionName = document.getElementById("promotionName");
    const promotionDiscount = document.getElementById("promotionDiscount");
    const promotionStartDate = document.getElementById("promotionStartDate");
    const promotionEndDate = document.getElementById("promotionEndDate");
    const promotionStatus = document.getElementById("promotionStatus");

    const promotionSearch = document.getElementById("promotionSearch");

    let editingPromotionRow = null;


    /* =========================
       OPEN MODAL
    ========================= */

    addPromotionBtn.addEventListener("click", function () {

        editingPromotionRow = null;

        promotionForm.reset();

        promotionModalTitle.textContent = "Add Promotion";

        promotionModal.classList.add("show");

    });


    /* =========================
       CLOSE MODAL
    ========================= */

    closePromotionModal.addEventListener("click", function () {

        promotionModal.classList.remove("show");

    });


    promotionModal.addEventListener("click", function (event) {

        if (event.target === promotionModal) {

            promotionModal.classList.remove("show");

        }

    });


    /* =========================
       FORMAT DATE
    ========================= */

    function formatPromotionDate(date) {

        const parts = date.split("-");

        const year = parts[0];
        const month = parseInt(parts[1]);
        const day = parseInt(parts[2]);

        const months = [
            "January",
            "February",
            "March",
            "April",
            "May",
            "June",
            "July",
            "August",
            "September",
            "October",
            "November",
            "December"
        ];

        return day + " " + months[month - 1] + " " + year;

    }


    /* =========================
       UPDATE COUNTERS
    ========================= */

    function updatePromotionCounters() {

        const rows = promotionTableBody.querySelectorAll("tr");

        let total = 0;
        let active = 0;
        let inactive = 0;

        rows.forEach(function (row) {

            total++;

            const status = row.querySelector(".promotion-status");

            if (status.classList.contains("active")) {
                active++;
            } else {
                inactive++;
            }

        });

        document.getElementById("totalPromotions").textContent = total;
        document.getElementById("activePromotions").textContent = active;
        document.getElementById("inactivePromotions").textContent = inactive;

    }


    /* =========================
       SAVE PROMOTION
    ========================= */

    promotionForm.addEventListener("submit", function (event) {

        event.preventDefault();

        const name = promotionName.value.trim();
        const discount = promotionDiscount.value;
        const startDate = promotionStartDate.value;
        const endDate = promotionEndDate.value;
        const status = promotionStatus.value;

        if (!name || !discount || !startDate || !endDate) {

            alert("Please complete all promotion fields.");

            return;

        }


        if (endDate < startDate) {

            alert("The end date cannot be before the start date.");

            return;

        }


        const formattedStartDate = formatPromotionDate(startDate);
        const formattedEndDate = formatPromotionDate(endDate);


        /* =========================
           EDIT EXISTING PROMOTION
        ========================= */

        if (editingPromotionRow) {

            editingPromotionRow.querySelector(".promotion-name").textContent = name;

            editingPromotionRow.cells[1].textContent = discount + "%";

            editingPromotionRow.cells[2].textContent = formattedStartDate;

            editingPromotionRow.cells[3].textContent = formattedEndDate;

            editingPromotionRow.dataset.startDate = startDate;
            editingPromotionRow.dataset.endDate = endDate;


            const statusElement =
                editingPromotionRow.querySelector(".promotion-status");

            statusElement.textContent =
                status === "active" ? "Active" : "Inactive";

            statusElement.className =
                "promotion-status " + status;


            const toggleButton =
                editingPromotionRow.querySelector(".promotion-toggle-btn");

            toggleButton.title =
                status === "active" ? "Deactivate" : "Activate";


            alert("Promotion updated successfully.");

        }


        /* =========================
           ADD NEW PROMOTION
        ========================= */

        else {

            const row = document.createElement("tr");

            row.dataset.startDate = startDate;
            row.dataset.endDate = endDate;


            row.innerHTML = `

                <td>
                    <div class="promotion-name">
                        ${name}
                    </div>
                </td>

                <td>
                    ${discount}%
                </td>

                <td>
                    ${formattedStartDate}
                </td>

                <td>
                    ${formattedEndDate}
                </td>

                <td>
                    <span class="promotion-status ${status}">
                        ${status === "active" ? "Active" : "Inactive"}
                    </span>
                </td>

                <td>

                    <div class="promotion-actions">

                        <button
                            class="promotion-edit-btn"
                            title="Edit"
                        >
                            <i class="fa-solid fa-pen"></i>
                        </button>

                        <button
                            class="promotion-toggle-btn"
                            title="${status === "active" ? "Deactivate" : "Activate"}"
                        >
                            <i class="fa-solid fa-power-off"></i>
                        </button>

                        <button
                            class="promotion-delete-btn"
                            title="Delete"
                        >
                            <i class="fa-solid fa-trash"></i>
                        </button>

                    </div>

                </td>

            `;


            promotionTableBody.appendChild(row);

            alert("Promotion added successfully.");

        }


        updatePromotionCounters();

        promotionModal.classList.remove("show");

        promotionForm.reset();

        editingPromotionRow = null;

    });


    /* =========================
       TABLE ACTIONS
    ========================= */

    promotionTableBody.addEventListener("click", function (event) {

        const button = event.target.closest("button");

        if (!button) {
            return;
        }


        const row = button.closest("tr");


        /* =========================
           EDIT
        ========================= */

        if (button.classList.contains("promotion-edit-btn")) {

            editingPromotionRow = row;

            promotionModalTitle.textContent = "Edit Promotion";

            promotionName.value =
                row.querySelector(".promotion-name").textContent.trim();

            promotionDiscount.value =
                row.cells[1].textContent.replace("%", "").trim();

            promotionStartDate.value =
                row.dataset.startDate;

            promotionEndDate.value =
                row.dataset.endDate;

            const status =
                row.querySelector(".promotion-status");

            promotionStatus.value =
                status.classList.contains("active")
                    ? "active"
                    : "inactive";

            promotionModal.classList.add("show");

        }


        /* =========================
           TOGGLE STATUS
        ========================= */

        if (button.classList.contains("promotion-toggle-btn")) {

            const status =
                row.querySelector(".promotion-status");

            if (status.classList.contains("active")) {

                status.classList.remove("active");
                status.classList.add("inactive");

                status.textContent = "Inactive";

                button.title = "Activate";

            } else {

                status.classList.remove("inactive");
                status.classList.add("active");

                status.textContent = "Active";

                button.title = "Deactivate";

            }

            updatePromotionCounters();

        }


        /* =========================
           DELETE
        ========================= */

        if (button.classList.contains("promotion-delete-btn")) {

            const name =
                row.querySelector(".promotion-name").textContent.trim();

            const confirmed =
                confirm("Are you sure you want to delete " + name + "?");

            if (confirmed) {

                row.remove();

                updatePromotionCounters();

            }

        }

    });


    /* =========================
       SEARCH
    ========================= */

    promotionSearch.addEventListener("input", function () {

        const searchValue =
            promotionSearch.value.toLowerCase().trim();

        const rows =
            promotionTableBody.querySelectorAll("tr");

        rows.forEach(function (row) {

            const promotionName =
                row.querySelector(".promotion-name")
                   .textContent
                   .toLowerCase();

            if (promotionName.includes(searchValue)) {

                row.style.display = "";

            } else {

                row.style.display = "none";

            }

        });

    });


    /* =========================
       INITIAL COUNTERS
    ========================= */

    updatePromotionCounters();

}


/* ========================================
   CUSTOMER MANAGEMENT
======================================== */

const customerModal = document.getElementById("customerModal");

if (customerModal) {

    const customerTableBody =
        document.getElementById("customerTableBody");

    const customerSearch =
        document.getElementById("customerSearch");

    const customerStatusFilter =
        document.getElementById("customerStatusFilter");

    const closeCustomerModal =
        document.getElementById("closeCustomerModal");


    /* =========================
       CLOSE MODAL
    ========================= */

    closeCustomerModal.addEventListener("click", function () {

        customerModal.classList.remove("show");

    });


    customerModal.addEventListener("click", function (event) {

        if (event.target === customerModal) {

            customerModal.classList.remove("show");

        }

    });


    /* =========================
       UPDATE CUSTOMER COUNTERS
    ========================= */

    function updateCustomerCounters() {

        const rows =
            customerTableBody.querySelectorAll("tr");

        let total = rows.length;
        let active = 0;

        rows.forEach(function (row) {

            if (row.dataset.status === "active") {
                active++;
            }

        });

        document.getElementById("totalCustomers").textContent = total;

        document.getElementById("activeCustomers").textContent = active;

    }


    /* =========================
       CUSTOMER SEARCH + FILTER
    ========================= */

    function filterCustomers() {

        const searchValue =
            customerSearch.value.toLowerCase().trim();

        const statusValue =
            customerStatusFilter.value;

        const rows =
            customerTableBody.querySelectorAll("tr");


        rows.forEach(function (row) {

            const customerName =
                row.querySelector(".customer-name strong")
                   .textContent
                   .toLowerCase();

            const customerEmail =
                row.cells[1].textContent.toLowerCase();


            const matchesSearch =
                customerName.includes(searchValue) ||
                customerEmail.includes(searchValue);


            const matchesStatus =
                statusValue === "all" ||
                row.dataset.status === statusValue;


            if (matchesSearch && matchesStatus) {

                row.style.display = "";

            } else {

                row.style.display = "none";

            }

        });

    }


    customerSearch.addEventListener(
        "input",
        filterCustomers
    );


    customerStatusFilter.addEventListener(
        "change",
        filterCustomers
    );


    /* =========================
       CUSTOMER ACTIONS
    ========================= */

    customerTableBody.addEventListener("click", function (event) {

        const button =
            event.target.closest("button");

        if (!button) {
            return;
        }


        const row =
            button.closest("tr");


        /* =========================
           VIEW CUSTOMER
        ========================= */

        if (button.classList.contains("customer-view-btn")) {

            const name =
                row.querySelector(".customer-name strong")
                   .textContent
                   .trim();

            const customerId =
                row.querySelector(".customer-name small")
                   .textContent
                   .trim();

            const email =
                row.cells[1].textContent.trim();

            const phone =
                row.cells[2].textContent.trim();

            const orders =
                row.cells[3].textContent.trim();

            const spent =
                row.cells[4].textContent.trim();

            const initials =
                row.querySelector(".customer-avatar")
                   .textContent
                   .trim();


            document.getElementById("detailAvatar").textContent =
                initials;

            document.getElementById("detailName").textContent =
                name;

            document.getElementById("detailCustomerId").textContent =
                customerId;

            document.getElementById("detailEmail").textContent =
                email;

            document.getElementById("detailPhone").textContent =
                phone;

            document.getElementById("detailOrders").textContent =
                orders;

            document.getElementById("detailSpent").textContent =
                spent;


            customerModal.classList.add("show");

        }


        /* =========================
           TOGGLE CUSTOMER
        ========================= */

        if (button.classList.contains("customer-toggle-btn")) {

            const status =
                row.querySelector(".customer-status");


            if (row.dataset.status === "active") {

                row.dataset.status = "inactive";

                status.textContent = "Inactive";

                status.classList.remove("active");
                status.classList.add("inactive");

                button.title = "Activate";

            } else {

                row.dataset.status = "active";

                status.textContent = "Active";

                status.classList.remove("inactive");
                status.classList.add("active");

                button.title = "Deactivate";

            }


            updateCustomerCounters();

            filterCustomers();

        }

    });


    /* =========================
       INITIAL COUNTERS
    ========================= */

    updateCustomerCounters();

}


/* ========================================
   STAFF & USERS MANAGEMENT
======================================== */

const userModal = document.getElementById("userModal");

if (userModal) {

    const userTableBody =
        document.getElementById("userTableBody");

    const userSearch =
        document.getElementById("userSearch");

    const userRoleFilter =
        document.getElementById("userRoleFilter");

    const addUserBtn =
        document.getElementById("addUserBtn");

    const closeUserModal =
        document.getElementById("closeUserModal");

    const cancelUserBtn =
        document.getElementById("cancelUserBtn");

    const userForm =
        document.getElementById("userForm");

    const userModalTitle =
        document.getElementById("userModalTitle");

    let editingUser = null;


    /* =========================
       OPEN ADD USER MODAL
    ========================= */

    addUserBtn.addEventListener("click", function () {

        editingUser = null;

        userModalTitle.textContent = "Add User";

        userForm.reset();

        userModal.classList.add("show");

    });


    /* =========================
       CLOSE MODAL
    ========================= */

    function closeModal() {

        userModal.classList.remove("show");

        userForm.reset();

        editingUser = null;

    }


    closeUserModal.addEventListener(
        "click",
        closeModal
    );


    cancelUserBtn.addEventListener(
        "click",
        closeModal
    );


    userModal.addEventListener("click", function (event) {

        if (event.target === userModal) {
            closeModal();
        }

    });


    /* =========================
       UPDATE COUNTERS
    ========================= */

    function updateUserCounters() {

        const rows =
            userTableBody.querySelectorAll("tr");

        let total = rows.length;
        let active = 0;
        let staff = 0;


        rows.forEach(function (row) {

            if (row.dataset.status === "active") {
                active++;
            }

            if (
                row.dataset.role === "staff" ||
                row.dataset.role === "manager"
            ) {
                staff++;
            }

        });


        document.getElementById("totalUsers").textContent =
            total;

        document.getElementById("activeUsers").textContent =
            active;

        document.getElementById("staffMembers").textContent =
            staff;

    }


    /* =========================
       SEARCH + ROLE FILTER
    ========================= */

    function filterUsers() {

        const searchValue =
            userSearch.value.toLowerCase().trim();

        const roleValue =
            userRoleFilter.value;

        const rows =
            userTableBody.querySelectorAll("tr");


        rows.forEach(function (row) {

            const name =
                row.querySelector(".user-name strong")
                   .textContent
                   .toLowerCase();

            const email =
                row.cells[1].textContent
                   .toLowerCase();


            const matchesSearch =
                name.includes(searchValue) ||
                email.includes(searchValue);


            const matchesRole =
                roleValue === "all" ||
                row.dataset.role === roleValue;


            if (matchesSearch && matchesRole) {

                row.style.display = "";

            } else {

                row.style.display = "none";

            }

        });

    }


    userSearch.addEventListener(
        "input",
        filterUsers
    );


    userRoleFilter.addEventListener(
        "change",
        filterUsers
    );


    /* =========================
       EDIT / TOGGLE
    ========================= */

    userTableBody.addEventListener("click", function (event) {

        const button =
            event.target.closest("button");

        if (!button) {
            return;
        }


        const row =
            button.closest("tr");


        /* EDIT */

        if (button.classList.contains("user-edit-btn")) {

            editingUser = row;

            userModalTitle.textContent =
                "Edit User";


            const name =
                row.querySelector(".user-name strong")
                   .textContent.trim();

            const email =
                row.cells[1].textContent.trim();

            const role =
                row.dataset.role;


            document.getElementById("userName").value =
                name;

            document.getElementById("userEmail").value =
                email;

            document.getElementById("userRole").value =
                role;

            document.getElementById("userPassword").value =
                "";


            userModal.classList.add("show");

        }


        /* TOGGLE STATUS */

        if (button.classList.contains("user-toggle-btn")) {

            const status =
                row.querySelector(".user-status");


            if (row.dataset.status === "active") {

                row.dataset.status = "inactive";

                status.textContent = "Inactive";

                status.classList.remove("active");
                status.classList.add("inactive");

            } else {

                row.dataset.status = "active";

                status.textContent = "Active";

                status.classList.remove("inactive");
                status.classList.add("active");

            }


            updateUserCounters();

        }

    });


    /* =========================
       SAVE USER
    ========================= */

    userForm.addEventListener("submit", function (event) {

        event.preventDefault();


        const name =
            document.getElementById("userName")
                   .value.trim();

        const email =
            document.getElementById("userEmail")
                   .value.trim();

        const role =
            document.getElementById("userRole")
                   .value;


        if (!name || !email || !role) {

            alert("Please complete all required fields.");

            return;

        }


        if (editingUser) {

            /* UPDATE EXISTING USER */

            editingUser.querySelector(
                ".user-name strong"
            ).textContent = name;

            editingUser.cells[1].textContent =
                email;

            editingUser.dataset.role =
                role;


            const roleElement =
                editingUser.querySelector(".user-role");

            roleElement.textContent =
                role === "admin"
                    ? "Administrator"
                    : role === "manager"
                        ? "Manager"
                        : "Staff";


            roleElement.className =
                "user-role " + role;


        } else {

            /* CREATE NEW USER */

            const initials =
                name
                    .split(" ")
                    .map(word => word.charAt(0))
                    .join("")
                    .substring(0, 2)
                    .toUpperCase();


            const newRow =
                document.createElement("tr");


            newRow.dataset.role = role;
            newRow.dataset.status = "active";


            const roleName =
                role === "admin"
                    ? "Administrator"
                    : role === "manager"
                        ? "Manager"
                        : "Staff";


            newRow.innerHTML = `

                <td>

                    <div class="user-name">

                        <div class="user-avatar">
                            ${initials}
                        </div>

                        <div>
                            <strong>${name}</strong>
                            <small>User #NEW</small>
                        </div>

                    </div>

                </td>

                <td>${email}</td>

                <td>
                    <span class="user-role ${role}">
                        ${roleName}
                    </span>
                </td>

                <td>
                    Never
                </td>

                <td>
                    <span class="user-status active">
                        Active
                    </span>
                </td>

                <td>

                    <div class="user-actions">

                        <button class="user-edit-btn">
                            <i class="fa-solid fa-pen"></i>
                        </button>

                        <button class="user-toggle-btn">
                            <i class="fa-solid fa-power-off"></i>
                        </button>

                    </div>

                </td>

            `;


            userTableBody.appendChild(newRow);

        }


        updateUserCounters();

        closeModal();

    });


    updateUserCounters();

}



/* ========================================
   REPORTS
======================================== */

const reportPeriod = document.getElementById("reportPeriod");

if (reportPeriod) {

    const updateReportBtn =
        document.getElementById("updateReportBtn");

    const printReportBtn =
        document.getElementById("printReportBtn");


    updateReportBtn.addEventListener("click", function () {

        const period = reportPeriod.value;

        const sales =
            document.getElementById("reportSales");

        const orders =
            document.getElementById("reportOrders");

        const average =
            document.getElementById("reportAverage");


        if (period === "today") {

            sales.textContent = "R2,850";
            orders.textContent = "21";
            average.textContent = "R136";

        }

        else if (period === "week") {

            sales.textContent = "R18,450";
            orders.textContent = "126";
            average.textContent = "R146";

        }

        else if (period === "month") {

            sales.textContent = "R76,820";
            orders.textContent = "534";
            average.textContent = "R144";

        }

        else if (period === "year") {

            sales.textContent = "R842,600";
            orders.textContent = "5,940";
            average.textContent = "R142";

        }


        alert("Report updated successfully.");

    });


    printReportBtn.addEventListener("click", function () {

        window.print();

    });

}



/* ========================================
   SETTINGS
======================================== */

const businessSettingsForm =
    document.getElementById("businessSettingsForm");

if (businessSettingsForm) {

    const saveHoursBtn =
        document.getElementById("saveHoursBtn");

    const saveAccountBtn =
        document.getElementById("saveAccountBtn");


    /* =========================
       BUSINESS INFORMATION
    ========================= */

    businessSettingsForm.addEventListener(
        "submit",
        function (event) {

            event.preventDefault();

            alert("Business information saved successfully.");

        }
    );


    /* =========================
       BUSINESS HOURS
    ========================= */

    saveHoursBtn.addEventListener(
        "click",
        function () {

            alert("Business hours saved successfully.");

        }
    );


    /* =========================
       ACCOUNT SETTINGS
    ========================= */

    saveAccountBtn.addEventListener(
        "click",
        function () {

            const name =
                document.getElementById("adminName").value.trim();

            const email =
                document.getElementById("adminEmail").value.trim();


            if (!name || !email) {

                alert("Please complete the administrator details.");

                return;

            }


            alert("Account settings saved successfully.");

        }
    );

}