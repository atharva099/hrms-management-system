<!DOCTYPE html>
<html>

<head>

    <title>CoreAxis CRM Login</title>

    <link rel="stylesheet" href="css/style.css?v=2">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

</head>

<body>

    <div class="login-container">

        <div class="login-box">

            <h1>
                CoreAxis CRM
            </h1>

            <p class="subtitle">
                HRMS & Employee Management System
            </p>

            <form action="login.php" method="POST">

                <input type="text" name="employee_id" id="employee_id" placeholder="Employee ID" required>

                <div class="password-box">

                    <input type="password" name="password" id="password" placeholder="Enter Password">

                    <span class="toggle-password" onclick="togglePassword()">

                        <i class="fa-solid fa-eye"></i>

                    </span>

                </div>

                <button type="submit">

                    Login

                </button>

            </form>

        </div>

    </div>

    <script>

        function togglePassword() {
            let password =
                document.getElementById("password");

            if (password.type === "password") {
                password.type = "text";
            }
            else {
                password.type = "password";
            }
        }

        document.getElementById("employee_id")
            .addEventListener("keypress", function (event) {
                if (event.key === "Enter") {
                    event.preventDefault();

                    document.getElementById("password").focus();
                }
            });

    </script>

</body>

</html>