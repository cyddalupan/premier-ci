<?php
if (!defined('ENV')) {
    define('ENV', 'prod');
}
if (ENV == "dev") {
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Cache-Control: post-check=0, pre-check=0", false);
  header("Pragma: no-cache");
}
?>
<style>
  body {
    background-color: #fff;
    font-family: Arial, sans-serif;
  }

  .container {
    max-width: 600px;
    margin: 50px auto;
    background-color: #fff;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    border-radius: 5px;
    overflow: hidden;
    text-align: center;
    /* Centered content */
  }

  .card-header {
    background-color: #007bff;
    color: #fff;
    padding: 15px;
    font-size: 1.25rem;
    text-align: center;
  }

  .card-body {
    padding: 20px;
  }

  .form-group {
    margin-bottom: 15px;
  }

  .result {
    background-color: #f1f1f1;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 15px;
    border-left: 5px solid #007bff;
  }

  textarea {
    width: 97%;
    height: 300px;
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    font-size: 1rem;
    resize: vertical;

    background-color: #fdfde3;
    background-image: repeating-linear-gradient(to bottom,
        #fdfde3,
        #fdfde3 35px,
        #eceadf 35px,
        #eceadf 37px);
  }

  button {
    background-color: #007bff;
    color: #fff;
    padding: 12px 30px;
    /* Bigger button */
    border: none;
    border-radius: 5px;
    /* More rounded */
    cursor: pointer;
    font-size: 1.1rem;
    /* Larger font */
    transition: background-color 0.3s;
  }

  button:hover {
    background-color: #0056b3;
    /* Darker blue on hover */
  }

  #skipButton {
    background-color: #6c757d;
    /* Grey color for Skip */
    color: #fff;
    padding: 12px 30px;
    /* Same padding */
    border: none;
    border-radius: 5px;
    /* Same rounded corners */
    cursor: pointer;
    font-size: 1.1rem;
    /* Same font size */
    transition: background-color 0.3s;
  }

  #skipButton:hover {
    background-color: #5a6268;
    /* Darker grey on hover */
  }

  .instruction {
    margin-bottom: 20px;
    font-size: 1.1rem;
    /* Slightly larger instruction font */
  }

  .progress-bar-container {
    width: 100%;
    background-color: #f3f3f3;
    border-radius: 5px;
    overflow: hidden;
    margin: 20px 0;
  }

  .progress-bar {
    height: 20px;
    background-color: #4caf50;
    transition: width 0.3s ease;
  }

  .progress-text {
    text-align: center;
    margin-top: 10px;
    font-size: 14px;
  }

  .accordion {
    background-color: #f7f7f7;
    border-radius: 5px;
    margin-bottom: 10px;
    border: 1px solid #ddd;
  }

  .accordion-item {
    border-bottom: 1px solid #ddd;
  }

  .accordion-item:last-child {
    border-bottom: none;
  }

  .accordion-header {
    padding: 10px;
    cursor: pointer;
    font-weight: bold;
    display: flex;
    justify-content: space-between;
    background-color: #007bff;
    color: white;
    border-radius: 5px;
    margin: 0;
  }

  .accordion-content {
    display: none;
    padding: 10px;
    background-color: white;
    border-top: none;
  }

  .accordion-content p {
    margin: 0;
  }

  .spinner {
    border: 8px solid #f3f3f3;
    /* Light grey */
    border-top: 8px solid #3498db;
    /* Blue */
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    position: absolute;
    /* This assumes you'll position it over your button */
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    z-index: 1000;
    /* Ensure it's on top of all other elements */
  }

  hr {
    border: 0;
    height: 1px;
    background-color: #007bff36;
    border-radius: 5px;
    margin: 20px 0;
  }

  @keyframes spin {
    0% {
      transform: rotate(0deg);
    }

    100% {
      transform: rotate(360deg);
    }
  }

  /* Time Style */
  .timer {
    display: flex;
    justify-content: center;
    margin: 10px 0;
    position: fixed;
    background: #ffffff;
    opacity: 0.9;
    left: 45px;
    top: 79px;
    padding: 3px;
    border-radius: 4px;
    border: solid lightgrey 1px;
  }

  .time {
    font-size: 12px;
    font-weight: bold;
    margin: 0 5px;
    padding: 10px;
    background-color: #007bff;
    color: white;
    border-radius: 5px;
  }

  .time-label {
    margin-top: 5px;
  }
</style>