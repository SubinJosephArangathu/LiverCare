<div id="addUserModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h3>Add New User</h3>

        <form method="post" class="modal-form">
            <label>Username</label>
            <input type="text" name="username" placeholder="Enter Username" required>

            <label>Password</label>
            <input type="password" name="password" placeholder="Enter Password" required>

            <label>Role</label>
            <select name="role">
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
            </select>

            <button type="submit" class="btn-primary full-width">Add User</button>
        </form>
    </div>
</div>

<style>
/* === Modal Styling (Cobalt Blue Theme) === */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background-color: rgba(0, 0, 0, 0.55);
    justify-content: center;
    align-items: center;
    opacity: 0;
    transition: opacity 0.3s ease;
    z-index: 9999;
}

.modal.show {
    opacity: 1;
}

.modal-content {
    background: #ffffff;
    padding: 25px 30px;
    border-radius: 12px;
    width: 400px;
    max-width: 90%;
    box-shadow: 0 5px 25px rgba(0, 0, 0, 0.3);
    transform: translateY(-25px);
    transition: transform 0.3s ease;
}

.modal.show .modal-content {
    transform: translateY(0);
}

.modal h3 {
    text-align: center;
    color: #002b7f; /* Cobalt Blue */
    margin-bottom: 20px;
}

.modal-form label {
    font-weight: bold;
    display: block;
    margin-bottom: 5px;
    color: #002b7f;
}

.modal-form input, .modal-form select {
    width: 100%;
    padding: 10px;
    margin-bottom: 15px;
    border: 1px solid #bbb;
    border-radius: 6px;
    transition: border-color 0.2s;
}

.modal-form input:focus, .modal-form select:focus {
    border-color: #002b7f;
    outline: none;
}

.btn-primary {
    background-color: #002b7f;
    color: #fff;
    border: none;
    padding: 10px 15px;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.3s ease;
}

.btn-primary:hover {
    background-color: #0040bf;
}

.full-width {
    width: 100%;
}

.close {
    float: right;
    font-size: 24px;
    font-weight: bold;
    color: #002b7f;
    cursor: pointer;
}

.close:hover {
    color: red;
}
</style>
