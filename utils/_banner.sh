#!/bin/bash
#
# Print banner art.

#######################################
# Print a board. 
# Globals:
#   BG_BROWN
#   NC
#   WHITE
#   CYAN_LIGHT
#   RED
#   CYAN_LIGHT
#   YELLOW
# Arguments:
#   None
#######################################
print_banner() {

  clear

  printf "\n\n"

  printf "${CYAN_LIGHT}";
  printf "                                                     ▄▄█▀▀▀▀▀▀▀█▄▄  \n";
  printf "                                                   ${CYAN_LIGHT}▄█▀${NC}   ${WHITE}▄▄${NC}      ${CYAN_LIGHT}▀█▄\n";
  printf "                                                   ${CYAN_LIGHT}█${NC}    ${WHITE}███${NC}         ${CYAN_LIGHT}█\n";
  printf "                                                   ${CYAN_LIGHT}█${NC}    ${WHITE}██▄         ${CYAN_LIGHT}█${NC}\n";
  printf "                                                   ${CYAN_LIGHT}█${NC}     ${WHITE}▀██▄${NC} ${WHITE}██${NC}    ${CYAN_LIGHT}█\n";
  printf "                                                   ${CYAN_LIGHT}█${NC}       ${WHITE}▀███▀${NC}    ${CYAN_LIGHT}█\n";
  printf "                                                   ${CYAN_LIGHT}▀█▄           ▄█▀\n";
  printf "                                                    ▄█    ▄▄▄▄█▀▀  \n";
  printf "                                                    █  ▄█▀        \n";
  printf "                                                    ▀▀▀▀          \n";
  printf "${NC}";

  printf "\n"

printf "${CYAN_LIGHT}";  
printf "██████╗░██╗░░░░░░██╗░░░░░░░██╗\n";
printf "██╔══██╗██║░░░░░░██║░░██╗░░██║\n";
printf "██████╔╝██║░░░░░░╚██╗████╗██╔╝\n";
printf "██╔═══╝░██║░░░░░░░████╔═████║░\n";
printf "██║░░░░░███████╗░░╚██╔╝░╚██╔╝░\n";
printf "╚═╝░░░░░╚══════╝░░░╚═╝░░░╚═╝░░\n";
printf "${NC}";
  
  

  printf "\n"
}
